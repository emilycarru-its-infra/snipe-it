<?php

namespace Tests\Feature\Leasing;

use App\Models\Asset;
use App\Models\Statuslabel;
use App\Services\Leasing\LeaseClosure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LeaseClosureTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function asset(Statuslabel $status, ?string $decommission, string $ownership = 'Lease to Return'): Asset
    {
        $asset = Asset::factory()->create(['status_id' => $status->id]);
        Asset::query()->whereKey($asset->id)->update([
            'lease_contract_id' => '4130-ECI20210601A',
            'decommission_date' => $decommission,
            'ownership_type' => $ownership,
        ]);

        return Asset::with('status')->find($asset->id);
    }

    public function test_returned_devices_close_the_lease()
    {
        // Rod's rule: a decommission date plus an archived return status is a
        // completed lease lifecycle. ECI20210601A is 23 of 23 in this state and
        // was still the worst row on the Extension Watch.
        $archived = Statuslabel::factory()->archived()->create();
        $assets = [
            $this->asset($archived, '2025-08-01'),
            $this->asset($archived, '2025-08-01'),
        ];

        $state = app(LeaseClosure::class)->summarise($assets);

        $this->assertTrue($state['is_closed']);
        $this->assertSame(2, $state['returned']);
        $this->assertSame(0, $state['open']);
    }

    public function test_a_bought_out_device_also_closes_its_side_of_the_lease()
    {
        // Buying the unit out ends the lease obligation even though the asset
        // stays on the books and deployable.
        $deployable = Statuslabel::factory()->rtd()->create();
        $state = app(LeaseClosure::class)->summarise([
            $this->asset($deployable, null, 'Purchased'),
        ]);

        $this->assertTrue($state['is_closed']);
        $this->assertSame(1, $state['bought_out']);
    }

    public function test_a_single_open_device_keeps_the_lease_open()
    {
        $archived = Statuslabel::factory()->archived()->create();
        $deployable = Statuslabel::factory()->rtd()->create();

        $state = app(LeaseClosure::class)->summarise([
            $this->asset($archived, '2025-08-01'),
            $this->asset($deployable, null),
        ]);

        $this->assertFalse($state['is_closed']);
        $this->assertSame(1, $state['open']);
        $this->assertCount(1, $state['open_assets']);
    }

    public function test_an_archived_device_without_a_date_still_closes_but_is_counted()
    {
        // The device is demonstrably out of service, so it cannot hold the lease
        // open — but the missing date is a paperwork gap worth reporting.
        $archived = Statuslabel::factory()->archived()->create();

        $state = app(LeaseClosure::class)->summarise([$this->asset($archived, null)]);

        $this->assertTrue($state['is_closed']);
        $this->assertSame(1, $state['archived_without_date']);
        $this->assertSame(0, $state['returned']);
    }

    public function test_a_lease_with_no_assets_is_not_reported_as_closed()
    {
        $state = app(LeaseClosure::class)->summarise([]);

        $this->assertFalse($state['is_closed']);
    }
}
