<?php

namespace Tests\Feature\Leasing;

use App\Models\Asset;
use App\Models\Statuslabel;
use App\Services\Leasing\LeaseOwnershipReconciler;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LeaseOwnershipReconcilerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function leasedAsset(string $statusName, string $ownership): Asset
    {
        $status = Statuslabel::factory()->rtd()->create(['name' => $statusName]);
        $asset = Asset::factory()->create(['status_id' => $status->id]);
        Asset::query()->whereKey($asset->id)->update([
            'lease_contract_id' => '4130-ECI20220201',
            'ownership_type' => $ownership,
        ]);

        return $asset->refresh();
    }

    public function test_a_buyout_status_pulls_ownership_across()
    {
        $asset = $this->leasedAsset('Active (Buyouts)', 'Lease to Return');

        $report = app(LeaseOwnershipReconciler::class)->run(true);

        $this->assertSame(1, $report['written']);
        $this->assertSame('Purchased', $asset->fresh()->ownership_type);
    }

    public function test_preview_reports_without_writing()
    {
        $asset = $this->leasedAsset('Active (Buyouts)', 'Lease to Return');

        $report = app(LeaseOwnershipReconciler::class)->run(false);

        $this->assertSame(1, $report['candidates']);
        $this->assertSame(0, $report['written']);
        $this->assertSame('Lease to Return', $asset->fresh()->ownership_type);
    }

    public function test_it_never_moves_an_asset_off_purchased()
    {
        // Only ever promotes to Purchased, so a deliberate change is safe.
        $asset = $this->leasedAsset('Active', 'Purchased');

        $report = app(LeaseOwnershipReconciler::class)->run(true);

        $this->assertSame(0, $report['candidates']);
        $this->assertSame('Purchased', $asset->fresh()->ownership_type);
    }

    public function test_an_unleased_asset_is_left_alone()
    {
        $status = Statuslabel::factory()->rtd()->create(['name' => 'Active (Buyouts)']);
        $asset = Asset::factory()->create(['status_id' => $status->id]);
        Asset::query()->whereKey($asset->id)->update(['ownership_type' => 'Lease to Return']);

        $report = app(LeaseOwnershipReconciler::class)->run(true);

        $this->assertSame(0, $report['candidates']);
        $this->assertSame('Lease to Return', $asset->fresh()->ownership_type);
    }

    public function test_a_legacy_device_is_not_treated_as_a_buyout()
    {
        // Its note defines it as "past their end of life date without
        // replacement" — a replacement-planning marker that applies to bought
        // and leased kit alike, so it says nothing about ownership.
        $asset = $this->leasedAsset('Active (Legacy)', 'Lease to Return');

        $report = app(LeaseOwnershipReconciler::class)->run(true);

        $this->assertSame(0, $report['candidates']);
        $this->assertSame('Lease to Return', $asset->fresh()->ownership_type);
    }

    public function test_a_faculty_purchase_does_not_claim_ecu_ownership()
    {
        // "Purchased" means purchased by faculty. The unit has left the lease,
        // but ECU does not own it, so ownership must not be rewritten.
        $asset = $this->leasedAsset('Purchased', 'Lease to Return');

        $report = app(LeaseOwnershipReconciler::class)->run(true);

        $this->assertSame(0, $report['candidates']);
        $this->assertSame('Lease to Return', $asset->fresh()->ownership_type);
    }

    public function test_running_twice_changes_nothing_the_second_time()
    {
        $this->leasedAsset('Active (Buyouts)', 'Lease to Return');
        $service = app(LeaseOwnershipReconciler::class);
        $service->run(true);

        $this->assertSame(0, $service->run(true)['written']);
    }
}
