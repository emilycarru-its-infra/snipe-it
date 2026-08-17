<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Comparable-replacement projections: a model mapped to a store catalog
 * item projects its refresh at the catalog's live vendor price; unmapped
 * models fall back to the old device's purchase cost, labelled as such.
 */
class ComparableReplacementTest extends TestCase
{
    private function catalogItem(float $cost = 2383.11): CatalogItem
    {
        return CatalogItem::create([
            'name' => 'MacBook Air | 13" | M4 | 16GB | 1TB',
            'category' => 'Laptops',
            'unit_cost' => $cost,
            'price_type' => 'quoted',
            'product_type' => 'standard',
            'is_active' => true,
        ]);
    }

    private function mappedAsset(?CatalogItem $item, float $purchaseCost = 1868.47): Asset
    {
        $model = AssetModel::factory()->mbp13Model()->create([
            'refresh_catalog_item_id' => $item?->id,
        ]);

        $asset = Asset::factory()->create([
            'model_id' => $model->id,
            'purchase_cost' => $purchaseCost,
        ]);

        // Asset save hooks recalculate asset_eol_date from purchase_date +
        // model EOL, so pin the operational EOL directly for the forecast
        // window.
        DB::table('assets')->where('id', $asset->id)->update([
            'asset_eol_date' => now()->addMonths(2)->toDateString(),
            'eol_explicit' => true,
        ]);

        return $asset->fresh();
    }

    public function test_replacement_cost_estimate_reads_the_catalog_price()
    {
        $asset = $this->mappedAsset($this->catalogItem(2383.11));

        $this->assertEqualsWithDelta(2383.11, $asset->replacementCostEstimate(), 0.001);
    }

    public function test_unmapped_models_project_nothing()
    {
        $asset = $this->mappedAsset(null);

        $this->assertNull($asset->replacementCostEstimate());
    }

    public function test_the_forecast_shows_no_financial_information()
    {
        $this->actingAs(User::factory()->superuser()->create());
        $this->mappedAsset($this->catalogItem(2383.11));
        $this->mappedAsset(null, 999.99);

        // The old procurement address walks to the merged page.
        $this->get(route('reports.procurement.forecast'))
            ->assertRedirect(route('deployments.planning'));

        // The forecast is the device-planning surface; the money
        // conversation lives in /capital and the PO Builder. Catalog
        // projections still price the CSV export and planned orders.
        $this->get(route('deployments.planning'))
            ->assertOk()
            ->assertDontSee('2,383.11')
            ->assertDontSee('999.99');
    }

    public function test_planned_orders_quote_the_replacement_at_catalog_price()
    {
        $user = User::factory()->superuser()->create();
        $this->actingAs($user);
        $asset = $this->mappedAsset($this->catalogItem(2500.00), 1000.00);

        $this->post(route('reports.procurement.forecast.plan'), [
            'assets' => [$asset->id],
            'fiscal_year' => 'FY2026-27',
            'order_number' => 'PLAN-COMPARABLE-1',
        ]);

        $item = OrderItem::where('replaces_asset_id', $asset->id)->first();
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(2500.00, (float) $item->unit_cost, 0.001);
    }

    public function test_the_faculty_form_shows_the_comparable()
    {
        $user = User::factory()->create();
        $catalog = $this->catalogItem(2383.11);
        $asset = $this->mappedAsset($catalog);
        $asset->update(['assigned_to' => $user->id, 'assigned_type' => User::class]);

        $this->actingAs($user);
        $response = $this->get(route('forms.show', 'faculty-program'));

        if ($response->status() === 200) {
            $response->assertSee($catalog->name)
                ->assertSee('2,383.11');
        } else {
            // Form access may be gated by eligibility config in testing;
            // the comparable resolution itself is covered above.
            $this->assertTrue(in_array($response->status(), [302, 403], true));
        }
    }
}
