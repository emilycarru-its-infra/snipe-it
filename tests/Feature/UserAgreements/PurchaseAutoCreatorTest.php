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

    private function programModel(): \App\Models\AssetModel
    {
        $category = \App\Models\Category::firstOrCreate(
            ['name' => 'Laptop'],
            ['category_type' => 'asset', 'created_by' => 1]
        );
        $manufacturer = \App\Models\Manufacturer::firstOrCreate(
            ['name' => 'Apple'],
            ['created_by' => 1]
        );

        return \App\Models\AssetModel::factory()->create([
            'category_id' => $category->id,
            'manufacturer_id' => $manufacturer->id,
        ]);
    }

    private function assetAssignedTo(User $user, Statuslabel $status, ?float $purchaseCost = 850.00): Asset
    {
        $asset = Asset::factory()->create([
            'model_id'      => $this->programModel()->id,
            'status_id'     => $status->id,
            'assigned_to'   => $user->id,
            'assigned_type' => User::class,
            'purchase_cost' => $purchaseCost,
        ]);

        return $asset->fresh();
    }

    /**
     * The buyout figure is the programme's residual math — letting any
     * lease-end device through once priced an old iPad at the MacBook
     * residual. Non-programme devices mint no purchase row.
     */
    public function test_lease_end_on_a_non_programme_device_mints_nothing(): void
    {
        $user = User::factory()->create();
        $tablet = \App\Models\Category::firstOrCreate(
            ['name' => 'Tablet'],
            ['category_type' => 'asset', 'created_by' => 1]
        );
        $model = \App\Models\AssetModel::factory()->create(['category_id' => $tablet->id]);
        $asset = Asset::factory()->create([
            'model_id'      => $model->id,
            'status_id'     => $this->neutralStatus()->id,
            'assigned_to'   => $user->id,
            'assigned_type' => User::class,
            'purchase_cost' => 1100.00,
        ]);

        $asset->status_id = $this->leaseEndStatus()->id;
        $asset->save();

        $this->assertSame(0, UserAgreement::where('asset_id', $asset->id)->count());
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
            // Eligible, not quoted: a status label changing is the machine
            // reaching lease end, not somebody asking to buy it.
            'lifecycle_stage' => 'eligible',
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
            'model_id'      => $this->programModel()->id,
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
            ->where('lifecycle_stage', 'eligible')->count());
    }
}
