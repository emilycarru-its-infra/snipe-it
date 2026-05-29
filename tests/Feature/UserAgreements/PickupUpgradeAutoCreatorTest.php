<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\UserAgreements\PickupUpgradeAutoCreator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PickupUpgradeAutoCreatorTest extends TestCase
{
    private function facultyUser(): User
    {
        $user  = User::factory()->create();
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        $user->groups()->attach($group->id);
        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $group->id]);
        return $user;
    }

    private function rtdStatus(): Statuslabel
    {
        return Statuslabel::factory()->rtd()->create();
    }

    private function assetCheckedOutTo(User $user, ?float $purchaseCost = 2700.00): Asset
    {
        return Asset::factory()->create([
            'status_id'     => $this->rtdStatus()->id,
            'assigned_to'   => $user->id,
            'assigned_type' => User::class,
            'purchase_cost' => $purchaseCost,
        ])->fresh();
    }

    private function assetWithLeaseEndingAt(User $user, Carbon $endDate, ?float $purchaseCost = 2200.00): Asset
    {
        $asset = Asset::factory()->create([
            'status_id'     => $this->rtdStatus()->id,
            'assigned_to'   => $user->id,
            'assigned_type' => User::class,
            'purchase_cost' => $purchaseCost,
        ]);

        $contract = Contract::create([
            'contract_number' => 'TEST-'.uniqid(),
            'name'            => 'Test contract for '.$asset->asset_tag,
            'end_date'        => $endDate->toDateString(),
            'is_active'       => true,
        ]);

        $contract->assets()->attach($asset->id);

        return $asset->fresh();
    }

    public function test_checkout_creates_pickup_and_upgrade_when_above_base(): void
    {
        $user = $this->facultyUser();
        $this->assetWithLeaseEndingAt($user, Carbon::now()->addMonths(2), 2200.00);

        // Now the user receives a NEW laptop above the program base.
        $newAsset = Asset::factory()->create([
            'status_id'     => $this->rtdStatus()->id,
            'purchase_cost' => 3000.00,
        ]);
        $newAsset->assigned_to   = $user->id;
        $newAsset->assigned_type = User::class;
        $newAsset->save();

        $this->assertDatabaseHas('user_agreements', [
            'user_id'        => $user->id,
            'asset_id'       => $newAsset->id,
            'agreement_type' => 'pickup',
        ]);

        $upgrade = UserAgreement::where('user_id', $user->id)
            ->where('asset_id', $newAsset->id)
            ->where('agreement_type', 'upgrade')
            ->first();

        $this->assertNotNull($upgrade);
        // base = 2383.11, device = 3000 → top_up = 616.89
        $this->assertEqualsWithDelta(3000.00 - 2383.11, (float) $upgrade->top_up_amount, 0.01);
    }

    public function test_checkout_creates_pickup_only_when_at_or_below_base(): void
    {
        $user = $this->facultyUser();
        $this->assetWithLeaseEndingAt($user, Carbon::now()->addMonths(3), 2200.00);

        $newAsset = Asset::factory()->create([
            'status_id'     => $this->rtdStatus()->id,
            'purchase_cost' => 2383.11,
        ]);
        $newAsset->assigned_to   = $user->id;
        $newAsset->assigned_type = User::class;
        $newAsset->save();

        $this->assertSame(1, UserAgreement::where('asset_id', $newAsset->id)->count());
        $this->assertNotNull(UserAgreement::where('asset_id', $newAsset->id)
            ->where('agreement_type', 'pickup')->first());
        $this->assertNull(UserAgreement::where('asset_id', $newAsset->id)
            ->where('agreement_type', 'upgrade')->first());
    }

    public function test_skip_when_user_is_not_faculty(): void
    {
        $user = User::factory()->create();
        // Not in any group → not faculty-eligible.

        $newAsset = Asset::factory()->create(['status_id' => $this->rtdStatus()->id, 'purchase_cost' => 3000]);
        $newAsset->assigned_to   = $user->id;
        $newAsset->assigned_type = User::class;
        $newAsset->save();

        $this->assertDatabaseMissing('user_agreements', ['asset_id' => $newAsset->id]);
    }

    public function test_skip_when_no_other_asset_near_lease_end(): void
    {
        $user = $this->facultyUser();
        $this->assetWithLeaseEndingAt($user, Carbon::now()->addYears(3), 2200.00); // far future

        $newAsset = Asset::factory()->create(['status_id' => $this->rtdStatus()->id, 'purchase_cost' => 3000]);
        $newAsset->assigned_to   = $user->id;
        $newAsset->assigned_type = User::class;
        $newAsset->save();

        $this->assertDatabaseMissing('user_agreements', ['asset_id' => $newAsset->id]);
    }

    public function test_skip_when_other_asset_lease_already_ended(): void
    {
        $user = $this->facultyUser();
        // Lease end is in the past — window is "near future", not
        // "any past date" — so this user does not qualify.
        $this->assetWithLeaseEndingAt($user, Carbon::now()->subMonths(2), 2200.00);

        $newAsset = Asset::factory()->create(['status_id' => $this->rtdStatus()->id, 'purchase_cost' => 3000]);
        $newAsset->assigned_to   = $user->id;
        $newAsset->assigned_type = User::class;
        $newAsset->save();

        $this->assertDatabaseMissing('user_agreements', ['asset_id' => $newAsset->id]);
    }

    public function test_is_idempotent_on_repeat_call(): void
    {
        $user = $this->facultyUser();
        $this->assetWithLeaseEndingAt($user, Carbon::now()->addMonths(2), 2200.00);

        $newAsset = $this->assetCheckedOutTo($user, 3000.00);

        $first  = app(PickupUpgradeAutoCreator::class)->ensureForCheckout($newAsset);
        $second = app(PickupUpgradeAutoCreator::class)->ensureForCheckout($newAsset);

        $this->assertSame($first['pickup']->id, $second['pickup']->id);
        $this->assertSame($first['upgrade']->id, $second['upgrade']->id);
        $this->assertSame(2, UserAgreement::where('asset_id', $newAsset->id)->count());
    }

    public function test_disabled_by_config(): void
    {
        config()->set('forms.pickup_auto_create.enabled', false);
        $user = $this->facultyUser();
        $this->assetWithLeaseEndingAt($user, Carbon::now()->addMonths(2), 2200.00);

        $newAsset = Asset::factory()->create(['status_id' => $this->rtdStatus()->id, 'purchase_cost' => 3000]);
        $newAsset->assigned_to   = $user->id;
        $newAsset->assigned_type = User::class;
        $newAsset->save();

        $this->assertDatabaseMissing('user_agreements', ['asset_id' => $newAsset->id]);
    }
}
