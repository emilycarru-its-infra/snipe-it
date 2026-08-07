<?php

namespace Tests\Feature\Leasing;

use App\Models\Asset;
use App\Models\Contract;
use App\Services\Leasing\LeaseNameSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LeaseNameSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function contract(string $schedule, string $name): Contract
    {
        return Contract::factory()->create([
            'contract_number' => $name,
            'name' => $name,
            'schedule_number' => $schedule,
            'type' => 'lease',
        ]);
    }

    private function leasedAsset(string $schedule, ?string $name): Asset
    {
        $asset = Asset::factory()->create();
        Asset::query()->whereKey($asset->id)->update([
            'lease_contract_id' => $schedule,
            'lease_contract_name' => $name,
        ]);

        return $asset->refresh();
    }

    public function test_a_stale_name_is_corrected_from_the_register()
    {
        // The drift this exists for: assets carried the superseded lease-end-FY
        // form, un-padded, while the register holds the commencement-FY name.
        $this->contract('4130-ECI20221001', 'Devices Leases FY22-23 #03');
        $asset = $this->leasedAsset('4130-ECI20221001', 'Devices Leases FY27-28 #1');

        $report = app(LeaseNameSyncService::class)->run(true);

        $this->assertSame(1, $report['written']);
        $this->assertSame('Devices Leases FY22-23 #03', $asset->fresh()->lease_contract_name);
    }

    public function test_a_missing_name_is_filled_in()
    {
        $this->contract('4130-ECI20200915', 'Devices Leases FY20-21 #03');
        $asset = $this->leasedAsset('4130-ECI20200915', null);

        app(LeaseNameSyncService::class)->run(true);

        $this->assertSame('Devices Leases FY20-21 #03', $asset->fresh()->lease_contract_name);
    }

    public function test_preview_reports_without_writing()
    {
        $this->contract('4130-ECI20221001', 'Devices Leases FY22-23 #03');
        $asset = $this->leasedAsset('4130-ECI20221001', 'Devices Leases FY27-28 #1');

        $report = app(LeaseNameSyncService::class)->run(false);

        $this->assertSame(1, $report['written']);
        $this->assertSame('Devices Leases FY27-28 #1', $asset->fresh()->lease_contract_name);
    }

    public function test_running_twice_changes_nothing_the_second_time()
    {
        $this->contract('4130-ECI20221001', 'Devices Leases FY22-23 #03');
        $this->leasedAsset('4130-ECI20221001', 'Devices Leases FY27-28 #1');

        $service = app(LeaseNameSyncService::class);
        $service->run(true);

        $this->assertSame(0, $service->run(true)['written']);
    }

    public function test_an_unknown_schedule_is_reported_and_its_name_left_alone()
    {
        // An asset on a schedule the register doesn't know is a finding. Blanking
        // its name would destroy a hand-entered value to no benefit.
        $asset = $this->leasedAsset('4130-ECI19990101', 'Hand Entered Name');

        $report = app(LeaseNameSyncService::class)->run(true);

        $this->assertSame(0, $report['written']);
        $this->assertSame(['4130-ECI19990101' => 1], $report['unmatched']);
        $this->assertSame('Hand Entered Name', $asset->fresh()->lease_contract_name);
    }

    public function test_a_register_row_without_a_schedule_number_matches_nothing()
    {
        // Placeholder rows exist with a null schedule_number; they must not
        // become a wildcard that renames unrelated assets.
        Contract::factory()->create([
            'name' => 'Devices Leases FY22-23 #01',
            'schedule_number' => null,
            'type' => 'lease',
        ]);
        $asset = $this->leasedAsset('4130-ECI20221001', 'Devices Leases FY27-28 #1');

        $report = app(LeaseNameSyncService::class)->run(true);

        $this->assertSame(0, $report['written']);
        $this->assertSame('Devices Leases FY27-28 #1', $asset->fresh()->lease_contract_name);
    }
}
