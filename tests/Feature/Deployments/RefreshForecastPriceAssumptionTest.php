<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\User;
use App\Services\Deployments\RefreshForecast;
use App\Services\Settings\Preferences;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The price lists only reach so far forward. A fiscal year past the last one
 * is priced by carrying the catalog price forward at the annual inflation
 * assumption, and the row says it was assumed, so a planner never reads an
 * assumption as a quote. Years the lists cover keep the catalog price as is.
 */
class RefreshForecastPriceAssumptionTest extends TestCase
{
    private function catalogItem(array $overrides = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'name' => 'Replacement Laptop',
            'category' => 'Laptops',
            'product_type' => 'standard',
            'vendor_sku' => 'TEST-'.uniqid(),
            'unit_cost' => 1000,
            'price_type' => 'quoted',
            'is_active' => true,
        ], $overrides));
    }

    /** A device whose End of Life lands in the given date, mapped to $catalog when one is passed. */
    private function dueAsset(string $eol, ?CatalogItem $catalog = null, ?float $purchaseCost = null): Asset
    {
        $model = AssetModel::factory()->create(['refresh_catalog_item_id' => $catalog?->id]);
        $asset = Asset::factory()->create(['model_id' => $model->id]);
        Asset::query()->whereKey($asset->id)->update([
            'asset_eol_date' => $eol,
            'lease_end_date' => null,
            'purchase_cost' => $purchaseCost,
        ]);

        return $asset->fresh();
    }

    public function test_a_year_the_price_lists_cover_keeps_the_catalog_price()
    {
        Preferences::update([
            'deployments.forecast_annual_inflation' => 0.05,
            'deployments.forecast_priced_through_fy' => 'FY2026-27',
        ]);
        $asset = $this->dueAsset('2026-10-01', $this->catalogItem());

        $row = (new RefreshForecast)->forFiscalYear('FY2026-27')->firstWhere('id', $asset->id);

        $this->assertEqualsWithDelta(1000.0, $row->replacement_estimate, 0.001);
        $this->assertSame(RefreshForecast::BASIS_CATALOG, $row->estimate_basis);
        $this->assertSame(0, $row->estimate_years_assumed);
    }

    public function test_a_year_past_the_price_lists_compounds_the_assumption()
    {
        Preferences::update([
            'deployments.forecast_annual_inflation' => 0.05,
            'deployments.forecast_priced_through_fy' => 'FY2026-27',
        ]);
        $asset = $this->dueAsset('2028-10-01', $this->catalogItem());

        $row = (new RefreshForecast)->forFiscalYear('FY2028-29')->firstWhere('id', $asset->id);

        $this->assertEqualsWithDelta(1102.50, $row->replacement_estimate, 0.001);
        $this->assertSame(RefreshForecast::BASIS_ASSUMED, $row->estimate_basis);
        $this->assertSame(2, $row->estimate_years_assumed);
    }

    public function test_no_inflation_still_flags_a_carried_forward_price()
    {
        Preferences::update(['deployments.forecast_priced_through_fy' => 'FY2026-27']);
        $asset = $this->dueAsset('2027-10-01', $this->catalogItem());

        $row = (new RefreshForecast)->forFiscalYear('FY2027-28')->firstWhere('id', $asset->id);

        $this->assertEqualsWithDelta(1000.0, $row->replacement_estimate, 0.001);
        $this->assertSame(RefreshForecast::BASIS_ASSUMED, $row->estimate_basis);
    }

    public function test_an_unmapped_model_keeps_its_old_purchase_cost()
    {
        Preferences::update([
            'deployments.forecast_annual_inflation' => 0.05,
            'deployments.forecast_priced_through_fy' => 'FY2026-27',
        ]);
        $asset = $this->dueAsset('2029-10-01', null, 800.0);

        $row = (new RefreshForecast)->forFiscalYear('FY2029-30')->firstWhere('id', $asset->id);

        $this->assertEqualsWithDelta(800.0, $row->replacement_estimate, 0.001);
        $this->assertSame(RefreshForecast::BASIS_ORIGINAL, $row->estimate_basis);
    }

    public function test_the_priced_through_year_comes_from_the_catalog_when_not_pinned()
    {
        $this->catalogItem(['quoted_at' => '2025-06-01', 'expires_at' => '2027-03-01']);
        $this->catalogItem(['quoted_at' => '2025-09-01', 'is_active' => false, 'expires_at' => '2031-01-01']);

        $this->assertSame('FY2026-27', RefreshForecast::pricedThroughFy());
        $this->assertSame(0, RefreshForecast::yearsPastPriceList('FY2026-27'));
        $this->assertSame(3, RefreshForecast::yearsPastPriceList('FY2029-30'));
    }

    public function test_an_undated_catalog_counts_as_priced_for_the_current_year()
    {
        $this->travelTo('2026-10-15');
        $this->catalogItem();

        $this->assertSame('FY2026-27', RefreshForecast::pricedThroughFy());
    }

    public function test_the_rate_reads_as_a_percentage()
    {
        $this->assertSame('3%', RefreshForecast::formatRate(0.03));
        $this->assertSame('2.5%', RefreshForecast::formatRate(0.025));
        $this->assertSame('0%', RefreshForecast::formatRate(0.0));
    }

    public function test_a_malformed_priced_through_year_is_refused()
    {
        $this->expectException(ValidationException::class);

        Preferences::update(['deployments.forecast_priced_through_fy' => '2026']);
    }

    public function test_the_csv_export_states_the_assumption()
    {
        Preferences::update([
            'deployments.forecast_annual_inflation' => 0.1,
            'deployments.forecast_priced_through_fy' => 'FY2026-27',
        ]);
        $this->dueAsset('2027-10-01', $this->catalogItem(['name' => 'Assumed Laptop']));

        $csv = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('deployments.planning', ['fiscal_year' => 'FY2027-28', 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('1100.00', $csv);
        $this->assertStringContainsString('Catalog: Assumed Laptop, assumed 10% a year past FY2026-27 prices', $csv);
    }

    public function test_a_covered_year_exports_the_plain_catalog_basis()
    {
        Preferences::update(['deployments.forecast_priced_through_fy' => 'FY2026-27']);
        $this->dueAsset('2026-10-01', $this->catalogItem(['name' => 'Listed Laptop']));

        $csv = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('deployments.planning', ['fiscal_year' => 'FY2026-27', 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Catalog: Listed Laptop', $csv);
        $this->assertStringNotContainsString('assumed', $csv);
    }

    public function test_the_api_returns_the_estimate_and_the_assumption()
    {
        Preferences::update([
            'deployments.forecast_annual_inflation' => 0.1,
            'deployments.forecast_priced_through_fy' => 'FY2026-27',
        ]);
        $asset = $this->dueAsset('2027-10-01', $this->catalogItem());

        $response = $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.deployments.planning', ['fiscal_year' => 'FY2027-28']))
            ->assertOk()
            ->assertJsonPath('payload.price_assumption.priced_through_fy', 'FY2026-27')
            ->assertJsonPath('payload.price_assumption.years_assumed', 1);

        $row = collect($response->json('payload.candidates'))->firstWhere('id', $asset->id);
        $this->assertEqualsWithDelta(1100.0, $row['replacement_estimate'], 0.001);
        $this->assertSame('assumed', $row['estimate_basis']);
    }
}
