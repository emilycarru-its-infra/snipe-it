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

    public function test_a_buyout_status_closes_the_lease_even_when_ownership_is_stale()
    {
        // Status is the signal that gets set operationally; ownership_type is
        // not kept up. Production carries a unit at "Active (Buyouts)" whose
        // ownership still reads "Lease to Return" — reading ownership alone
        // left it open and held its whole lease open with it.
        $buyout = Statuslabel::factory()->rtd()->create(['name' => 'Active (Buyouts)']);
        $state = app(LeaseClosure::class)->summarise([
            $this->asset($buyout, null, 'Lease to Return'),
        ]);

        $this->assertTrue($state['is_closed']);
        $this->assertSame(1, $state['bought_out']);
    }

    public function test_a_retained_legacy_device_closes_the_lease()
    {
        $legacy = Statuslabel::factory()->rtd()->create(['name' => 'Active (Legacy)']);
        $state = app(LeaseClosure::class)->summarise([
            $this->asset($legacy, null, 'Lease to Return'),
        ]);

        $this->assertTrue($state['is_closed']);
        $this->assertSame(1, $state['bought_out']);
    }

    public function test_a_purchased_ownership_still_closes_when_no_status_says_so()
    {
        // Kept as a corroborating signal for records where only ownership was
        // updated.
        $deployable = Statuslabel::factory()->rtd()->create(['name' => 'Active']);
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
