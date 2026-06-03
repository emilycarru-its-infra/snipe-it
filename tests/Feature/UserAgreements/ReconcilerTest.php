<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\FormAccess;
use App\Services\UserAgreements\Reconciler;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReconcilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('forms.pickup_auto_create.base_program_price', 2383.11);
        config()->set('forms.purchase_auto_create.lease_end_status_labels', ['Active (Lease End)']);
    }

    private function facultyUser(): User
    {
        $user  = User::factory()->create();
        // Reuse the eligibility group across faculty users — a full sweep
        // creates several, and 'Regular Faculty' is unique on permission_groups.
        $group = Group::where('name', 'Regular Faculty')->first()
            ?? Group::factory()->create(['name' => 'Regular Faculty']);
        $user->groups()->attach($group->id);
        FormEligibility::firstOrCreate(['form_slug' => 'faculty-program', 'group_id' => $group->id]);
        FormAccess::flush();
        return $user;
    }

    private function leaseEndStatus(): Statuslabel
    {
        return Statuslabel::factory()->rtd()->create(['name' => 'Active (Lease End)']);
    }

    private function rtdStatus(): Statuslabel
    {
        return Statuslabel::factory()->rtd()->create();
    }

    private function assetFor(User $user, Statuslabel $status, ?float $cost = null): Asset
    {
        return Asset::factory()->create([
            'status_id'     => $status->id,
            'assigned_to'   => $user->id,
            'assigned_type' => User::class,
            'purchase_cost' => $cost,
        ])->fresh();
    }

    private function attachContract(Asset $asset, Carbon $endDate): Contract
    {
        $contract = Contract::create([
            'contract_number' => 'TEST-'.uniqid(),
            'name'            => 'Test contract '.$asset->asset_tag,
            'end_date'        => $endDate->toDateString(),
            'is_active'       => true,
        ]);
        $contract->assets()->attach($asset->id);
        return $contract;
    }

    // ---- Eugenia-shaped scenarios ----

    public function test_pickup_row_created_for_long_held_faculty_asset(): void
    {
        // Faculty member holding a laptop checked out long ago, no
        // pickup row was ever generated. Reconciler should create
        // one.
        $user  = $this->facultyUser();
        $asset = $this->assetFor($user, $this->rtdStatus(), 2700.00);

        $report = app(Reconciler::class)->reconcileForUser($user);

        $this->assertSame(1, $report->createdPickup);
        $this->assertDatabaseHas('user_agreements', [
            'user_id'        => $user->id,
            'asset_id'       => $asset->id,
            'agreement_type' => 'pickup',
        ]);
    }

    public function test_upgrade_row_created_when_device_above_base(): void
    {
        $user  = $this->facultyUser();
        $asset = $this->assetFor($user, $this->rtdStatus(), 3000.00);

        $report = app(Reconciler::class)->reconcileForUser($user);

        $this->assertSame(1, $report->createdUpgrade);

        $upgrade = UserAgreement::where('user_id', $user->id)
            ->where('asset_id', $asset->id)
            ->where('agreement_type', 'upgrade')
            ->first();
        $this->assertNotNull($upgrade);
        $this->assertEqualsWithDelta(3000.00 - 2383.11, (float) $upgrade->top_up_amount, 0.01);
    }

    public function test_no_upgrade_row_when_device_at_or_below_base(): void
    {
        $user  = $this->facultyUser();
        $this->assetFor($user, $this->rtdStatus(), 2383.11);

        $report = app(Reconciler::class)->reconcileForUser($user);

        $this->assertSame(0, $report->createdUpgrade);
    }

    public function test_purchase_row_created_when_lease_already_ended(): void
    {
        // L002916 shape: faculty still holding a laptop whose linked
        // contract end_date is months in the past.
        $user  = $this->facultyUser();
        $asset = $this->assetFor($user, $this->rtdStatus(), 2523.85);
        $this->attachContract($asset, Carbon::now()->subMonths(2));

        $report = app(Reconciler::class)->reconcileForUser($user);

        $this->assertSame(1, $report->createdPurchase);

        $purchase = UserAgreement::where('user_id', $user->id)
            ->where('asset_id', $asset->id)
            ->where('agreement_type', 'purchase')
            ->first();
        $this->assertNotNull($purchase);
        $this->assertSame(2523.85, (float) $purchase->buyout_cost);
        $this->assertSame($asset->asset_tag, $purchase->old_asset_tag);
    }

    public function test_status_flipped_when_lease_already_ended(): void
    {
        $leaseEnd = $this->leaseEndStatus();
        $user     = $this->facultyUser();
        $asset    = $this->assetFor($user, $this->rtdStatus(), 2523.85);
        $this->attachContract($asset, Carbon::now()->subMonths(2));

        $report = app(Reconciler::class)->reconcileForUser($user);

        $this->assertSame(1, $report->statusFlipped);
        $this->assertSame($leaseEnd->id, $asset->fresh()->status_id);
    }

    public function test_status_not_flipped_when_already_lease_end(): void
    {
        $leaseEnd = $this->leaseEndStatus();
        $user     = $this->facultyUser();
        $asset    = $this->assetFor($user, $leaseEnd, 2523.85);
        $this->attachContract($asset, Carbon::now()->subMonths(2));

        $report = app(Reconciler::class)->reconcileForUser($user);

        $this->assertSame(0, $report->statusFlipped);
    }

    public function test_idempotent_second_pass_makes_no_changes(): void
    {
        $this->leaseEndStatus();
        $user  = $this->facultyUser();
        $asset = $this->assetFor($user, $this->rtdStatus(), 3000.00);
        $this->attachContract($asset, Carbon::now()->subMonths(1));

        // First pass: creates everything.
        $first = app(Reconciler::class)->reconcileForUser($user);
        $this->assertTrue($first->hasChanges());

        // Second pass: nothing left to do.
        $second = app(Reconciler::class)->reconcileForUser($user);
        $this->assertFalse($second->hasChanges());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->leaseEndStatus();
        $user  = $this->facultyUser();
        $asset = $this->assetFor($user, $this->rtdStatus(), 3000.00);
        $this->attachContract($asset, Carbon::now()->subMonths(1));

        $report = app(Reconciler::class)->reconcileForUser($user, dryRun: true);

        $this->assertSame(1, $report->plannedPickup);
        $this->assertSame(1, $report->plannedUpgrade);
        $this->assertSame(1, $report->plannedPurchase);
        $this->assertSame(1, $report->plannedStatusFlip);
        $this->assertSame(0, UserAgreement::where('asset_id', $asset->id)->count());
    }

    public function test_skips_non_faculty_users_in_sweep(): void
    {
        $randomUser = User::factory()->create();
        $this->assetFor($randomUser, $this->rtdStatus(), 3000.00);

        $reports = app(Reconciler::class)->reconcileAll();

        // The sweep skips non-faculty users entirely, so the random user
        // never shows up in the reports and gets no agreement rows.
        $reportedUserIds = array_map(fn ($r) => $r->userId, $reports);
        $this->assertNotContains($randomUser->id, $reportedUserIds);
        $this->assertSame(0, UserAgreement::where('user_id', $randomUser->id)->count());
    }
}
