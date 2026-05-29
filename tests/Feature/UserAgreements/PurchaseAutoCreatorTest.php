<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\UserAgreements\PurchaseAutoCreator;
use Tests\TestCase;

class PurchaseAutoCreatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin the trigger to a known name regardless of the test
        // environment so these tests do not depend on the
        // USER_AGREEMENT_LEASE_END_STATUS_LABELS env default.
        config()->set('forms.purchase_auto_create.lease_end_status_labels', ['Active (Lease End)']);
    }

    private function leaseEndStatus(): Statuslabel
    {
        return Statuslabel::factory()->rtd()->create([
            'name' => 'Active (Lease End)',
        ]);
    }

    private function neutralStatus(): Statuslabel
    {
        return Statuslabel::factory()->rtd()->create(['name' => 'Active']);
    }

    private function assetAssignedTo(User $user, Statuslabel $status, ?float $purchaseCost = 850.00): Asset
    {
        $asset = Asset::factory()->create([
            'status_id'     => $status->id,
            'assigned_to'   => $user->id,
            'assigned_type' => User::class,
            'purchase_cost' => $purchaseCost,
        ]);

        return $asset->fresh();
    }

    public function test_status_change_to_lease_end_creates_purchase_row(): void
    {
        $user      = User::factory()->create();
        $leaseEnd  = $this->leaseEndStatus();
        $asset     = $this->assetAssignedTo($user, $this->neutralStatus(), 900.00);

        $asset->status_id = $leaseEnd->id;
        $asset->save();

        $this->assertDatabaseHas('user_agreements', [
            'user_id'         => $user->id,
            'asset_id'        => $asset->id,
            'agreement_type'  => 'purchase',
            'lifecycle_stage' => 'quoted',
        ]);

        $agreement = UserAgreement::where('asset_id', $asset->id)->first();
        $this->assertSame(900.00, (float) $agreement->buyout_cost);
        $this->assertSame($asset->asset_tag, $agreement->old_asset_tag);
        $this->assertSame($asset->serial, $agreement->old_serial);
    }

    public function test_skip_when_no_assigned_user(): void
    {
        $leaseEnd = $this->leaseEndStatus();
        $asset    = Asset::factory()->create([
            'status_id'     => $this->neutralStatus()->id,
            'assigned_to'   => null,
            'assigned_type' => null,
            'purchase_cost' => 700.00,
        ]);

        $asset->status_id = $leaseEnd->id;
        $asset->save();

        $this->assertDatabaseMissing('user_agreements', ['asset_id' => $asset->id]);
    }

    public function test_skip_when_status_not_in_config(): void
    {
        $user   = User::factory()->create();
        $other  = Statuslabel::factory()->rtd()->create(['name' => 'In Repair']);
        $asset  = $this->assetAssignedTo($user, $this->neutralStatus());

        $asset->status_id = $other->id;
        $asset->save();

        $this->assertDatabaseMissing('user_agreements', ['asset_id' => $asset->id]);
    }

    public function test_is_idempotent_when_open_purchase_already_exists(): void
    {
        $user     = User::factory()->create();
        $leaseEnd = $this->leaseEndStatus();
        $asset    = $this->assetAssignedTo($user, $leaseEnd, 600.00);

        // Asset::factory()->create() runs the `creating` lifecycle, not
        // `updated`, so the observer's auto-create path never fires
        // here. Both ensureFor() calls below exercise the service
        // directly to assert idempotency.
        $first  = app(PurchaseAutoCreator::class)->ensureFor($asset);
        $second = app(PurchaseAutoCreator::class)->ensureFor($asset);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, UserAgreement::where('asset_id', $asset->id)->count());
    }

    public function test_closed_purchase_row_does_not_block_new_one(): void
    {
        $user     = User::factory()->create();
        $leaseEnd = $this->leaseEndStatus();
        $asset    = $this->assetAssignedTo($user, $this->neutralStatus(), 750.00);

        UserAgreement::create([
            'agreement_type'  => 'purchase',
            'user_id'         => $user->id,
            'asset_id'        => $asset->id,
            'lifecycle_stage' => 'closed_buyout',
            'buyout_cost'     => 750,
        ]);

        $asset->status_id = $leaseEnd->id;
        $asset->save();

        // One closed + one new open.
        $this->assertSame(2, UserAgreement::where('asset_id', $asset->id)->count());
        $this->assertSame(1, UserAgreement::where('asset_id', $asset->id)
            ->where('lifecycle_stage', 'quoted')->count());
    }
}
