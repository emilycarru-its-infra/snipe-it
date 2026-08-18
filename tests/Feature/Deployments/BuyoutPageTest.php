<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Models\LeaseDecision;
use App\Models\User;
use Tests\TestCase;

/**
 * The per-device buyout page — the link handed around the lessor
 * thread — and the planning page's buyout decision that feeds it.
 */
class BuyoutPageTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_the_page_shows_the_devices_buyout_by_tag()
    {
        $asset = Asset::factory()->create(['asset_tag' => 'BUY-PAGE-1']);
        AssetBuyout::create([
            'asset_id' => $asset->id,
            'status' => 'approved',
            'requested_at' => now()->subDays(10),
            'quote_amount' => 1772.00,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('buyouts.show', 'BUY-PAGE-1'))
            ->assertOk()
            ->assertSee('BUY-PAGE-1')
            ->assertSee(trans('admin/deployments/general.buyout_status_approved'));
    }

    /**
     * The link handed around the lessor thread carries whatever identifier
     * the sender had — sometimes the tag, sometimes the serial off the
     * chassis. Both resolve to the same page.
     */
    public function test_the_page_resolves_by_serial_too()
    {
        $asset = Asset::factory()->create(['asset_tag' => 'BUY-PAGE-9', 'serial' => 'BUYPAGESERIAL9']);
        AssetBuyout::create([
            'asset_id' => $asset->id,
            'status' => 'approved',
            'requested_at' => now()->subDays(10),
            'quote_amount' => 1772.00,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('buyouts.show', 'BUYPAGESERIAL9'))
            ->assertOk()
            ->assertSee('BUY-PAGE-9');
    }

    public function test_a_device_without_a_buyout_offers_to_open_one()
    {
        $asset = Asset::factory()->create(['asset_tag' => 'BUY-PAGE-2']);

        $this->actingAs($this->superuser())
            ->get(route('buyouts.show', 'BUY-PAGE-2'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.buyout_open_button'));

        $this->actingAs($this->superuser())
            ->post(route('buyouts.open'), ['asset_id' => $asset->id]);

        $buyout = AssetBuyout::where('asset_id', $asset->id)->first();
        $this->assertNotNull($buyout);
        $this->assertEquals('requested', $buyout->status);

        // A second open against the same device is refused.
        $this->actingAs($this->superuser())
            ->post(route('buyouts.open'), ['asset_id' => $asset->id])
            ->assertSessionHas('error');
        $this->assertEquals(1, AssetBuyout::where('asset_id', $asset->id)->count());
    }

    public function test_the_in_flight_table_links_each_device_row_to_its_page()
    {
        $asset = Asset::factory()->create(['asset_tag' => 'BUY-PAGE-3']);
        AssetBuyout::create([
            'asset_id' => $asset->id,
            'status' => 'quoted',
            'requested_at' => now()->subDays(3),
        ]);

        $this->actingAs($this->superuser())
            ->get(route('deployments.decommissioning'))
            ->assertOk()
            ->assertSee(route('buyouts.show', 'BUY-PAGE-3', false), false);
    }

    public function test_planning_buyout_decision_records_both_sides_at_the_estimate()
    {
        // A lease-end device — the buyout exclusion applies to the lease
        // window (a buyout is a lease decision; owned devices have nothing
        // to buy out).
        $asset = Asset::factory()->create(['asset_tag' => 'BUY-PLAN-1']);
        Asset::query()->whereKey($asset->id)->update([
            'asset_eol_date' => null,
            'lease_end_date' => '2026-10-01',
        ]);

        $this->actingAs($this->superuser())
            ->post(route('deployments.planning.buyout'), [
                'asset_ids' => [$asset->id],
                'fiscal_year' => 'FY2026-27',
                'buyout_estimate' => 850.00,
            ])
            ->assertRedirect(route('deployments.planning', ['fiscal_year' => 'FY2026-27']));

        $decision = LeaseDecision::where('asset_id', $asset->id)->where('decision_type', 'buyout')->first();
        $this->assertEquals('approved', $decision->status);
        $this->assertEqualsWithDelta(850.00, (float) $decision->amount, 0.001);

        $buyout = AssetBuyout::where('asset_id', $asset->id)->first();
        $this->assertEquals('requested', $buyout->status);
        $this->assertEqualsWithDelta(850.00, (float) $buyout->quote_amount, 0.001);

        // The approved buyout takes the device off the refresh list.
        $forecast = new \App\Services\Deployments\RefreshForecast;
        $this->assertFalse($forecast->forFiscalYear('FY2026-27')->contains('id', $asset->id));

        // The estimate is required.
        $this->actingAs($this->superuser())
            ->post(route('deployments.planning.buyout'), [
                'asset_ids' => [$asset->id],
                'fiscal_year' => 'FY2026-27',
            ])
            ->assertSessionHasErrors('buyout_estimate');
    }
}
