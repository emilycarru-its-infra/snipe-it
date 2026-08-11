<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\LeaseDecision;
use App\Services\Deployments\RefreshForecast;
use Tests\TestCase;

/**
 * A lease end pulls a device into the fiscal year it falls in, because the
 * lease end is a return window. An approved buyout removes that window: the
 * device is ours and stays in service, so only our own End of Life says when
 * it should be replaced.
 *
 * Without this, a bought-out device is pinned to the FY its lease happened to
 * end in and cannot be planned into any later year — which is exactly the case
 * of the studio towers whose refresh moved out a year.
 */
class RefreshForecastBuyoutTest extends TestCase
{
    /**
     * AssetFactory's afterMaking hook always recomputes asset_eol_date from
     * purchase_date, discarding anything passed to create() — so the EOL has
     * to be stamped afterwards, quietly, to also skip the observer that
     * back-fills it.
     */
    private function leasedAsset(?string $eol = null): Asset
    {
        $asset = Asset::factory()->create([
            'lease_contract_id' => '4130-TEST20220101',
            'lease_end_date' => '2026-12-31',
        ]);

        $asset->asset_eol_date = $eol;
        $asset->saveQuietly();

        return $asset->refresh();
    }

    public function test_a_lease_ending_in_the_year_puts_a_device_in_that_forecast()
    {
        $asset = $this->leasedAsset();

        $found = (new RefreshForecast)->forFiscalYear('FY2026-27');

        $this->assertTrue($found->contains('id', $asset->id));
    }

    public function test_an_approved_contract_buyout_drops_the_lease_trigger()
    {
        $asset = $this->leasedAsset();

        LeaseDecision::factory()->create([
            'contract_reference' => '4130-TEST20220101',
            'asset_id' => null,
            'decision_type' => 'buyout',
            'status' => 'approved',
        ]);

        $found = (new RefreshForecast)->forFiscalYear('FY2026-27');

        $this->assertFalse($found->contains('id', $asset->id));
    }

    public function test_a_per_asset_buyout_drops_only_that_device()
    {
        $boughtOut = $this->leasedAsset();
        $stillLeased = $this->leasedAsset();

        LeaseDecision::factory()->create([
            'contract_reference' => '4130-TEST20220101',
            'asset_id' => $boughtOut->id,
            'decision_type' => 'buyout',
            'status' => 'approved',
        ]);

        $found = (new RefreshForecast)->forFiscalYear('FY2026-27');

        $this->assertFalse($found->contains('id', $boughtOut->id));
        $this->assertTrue($found->contains('id', $stillLeased->id));
    }

    public function test_a_pending_buyout_is_not_a_decision_yet()
    {
        $asset = $this->leasedAsset();

        LeaseDecision::factory()->create([
            'contract_reference' => '4130-TEST20220101',
            'asset_id' => null,
            'decision_type' => 'buyout',
            'status' => 'pending',
        ]);

        $found = (new RefreshForecast)->forFiscalYear('FY2026-27');

        $this->assertTrue($found->contains('id', $asset->id));
    }

    public function test_a_return_decision_leaves_the_lease_trigger_alone()
    {
        $asset = $this->leasedAsset();

        LeaseDecision::factory()->create([
            'contract_reference' => '4130-TEST20220101',
            'asset_id' => null,
            'decision_type' => 'return',
            'status' => 'approved',
        ]);

        $found = (new RefreshForecast)->forFiscalYear('FY2026-27');

        $this->assertTrue($found->contains('id', $asset->id));
    }

    /**
     * The point of the change: with the lease trigger gone, the End of Life
     * date decides the year, so a bought-out device can be planned forward.
     */
    public function test_a_bought_out_device_follows_its_end_of_life_into_a_later_year()
    {
        $asset = $this->leasedAsset('2027-12-01');

        LeaseDecision::factory()->create([
            'contract_reference' => '4130-TEST20220101',
            'asset_id' => null,
            'decision_type' => 'buyout',
            'status' => 'approved',
        ]);

        $forecast = new RefreshForecast;

        $this->assertFalse($forecast->forFiscalYear('FY2026-27')->contains('id', $asset->id));
        $this->assertTrue($forecast->forFiscalYear('FY2027-28')->contains('id', $asset->id));
    }
}
