<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\Deployments\PlanningHorizon;
use Carbon\Carbon;
use Tests\Support\DeclaresLessors;
use Tests\TestCase;

/**
 * The planning horizon: the next fiscal years side by side, each column
 * built from the same per-FY forecast and capital request its links open.
 */
class PlanningHorizonTest extends TestCase
{
    use DeclaresLessors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareLessors();
        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asset(array $native, array $attrs = []): Asset
    {
        $active = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create(array_merge(['status_id' => $active->id], $attrs));
        Asset::query()->whereKey($asset->id)->update(array_merge([
            'asset_eol_date' => null,
            'lease_end_date' => null,
        ], $native));

        return $asset->fresh();
    }

    private function modelPricedAt(float $cost): AssetModel
    {
        $catalog = CatalogItem::create([
            'name' => 'Horizon Laptop '.$cost,
            'category' => 'Laptops',
            'estimated_cost' => $cost,
            'price_type' => 'estimate',
            'product_type' => 'standard',
            'is_active' => true,
        ]);

        return AssetModel::factory()->create(['refresh_catalog_item_id' => $catalog->id]);
    }

    /** FY2027-28: one lease device on the forecast, one device on a wave. */
    private function seedNextYear(): DeploymentWave
    {
        // A lease ending in FY2027-28: its value is the contracted envelope,
        // and the device itself is due that year at its original cost.
        $this->asset([
            'lease_contract_id' => 'QQ-HZN-1',
            'lease_end_date' => '2027-09-01',
            'ownership_type' => 'Lease to Return',
        ], ['purchase_cost' => 3000]);

        // A device whose own dates point at FY2029-30, planned onto an
        // FY2027-28 wave: the wave's year wins, priced at the wave model.
        $replaced = $this->asset(['asset_eol_date' => '2029-06-01']);
        $wave = DeploymentWave::create(['name' => 'Horizon Wave', 'fiscal_year' => 'FY2027-28']);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $replaced->id,
            'model_id' => $this->modelPricedAt(1500)->id,
            'stage_id' => DeploymentStage::firstOrCreate(['slug' => 'planned'], ['name' => 'Planned'])->id,
        ]);

        return $wave;
    }

    public function test_the_horizon_spans_the_next_four_fiscal_years()
    {
        $this->assertSame(
            ['FY2027-28', 'FY2028-29', 'FY2029-30', 'FY2030-31'],
            PlanningHorizon::fiscalYears()
        );
    }

    public function test_each_column_follows_the_decision_chain()
    {
        $this->seedNextYear();

        // An owned device reaching End of Life in FY2028-29, priced from
        // its model's refresh catalog item.
        $this->asset(['asset_eol_date' => '2028-06-01'], ['model_id' => $this->modelPricedAt(2000)->id]);

        $columns = collect((new PlanningHorizon)->columns())->keyBy('fy');

        $next = $columns['FY2027-28'];
        $this->assertSame(1, $next['forecast_devices']);
        $this->assertSame(1, $next['wave_devices']);
        $this->assertSame(2, $next['devices']);
        $this->assertEqualsWithDelta(4500.0, $next['cost'], 0.001);
        $this->assertEqualsWithDelta(3000.0, $next['envelope'], 0.001);
        $this->assertEqualsWithDelta(-1500.0, $next['gap'], 0.001);
        $this->assertCount(1, $next['waves']);

        $after = $columns['FY2028-29'];
        $this->assertSame(1, $after['devices']);
        $this->assertEqualsWithDelta(2000.0, $after['cost'], 0.001);
        $this->assertEqualsWithDelta(0.0, $after['envelope'], 0.001);

        // The waved device left the year its own dates point at.
        $this->assertSame(0, $columns['FY2029-30']['devices']);
    }

    public function test_the_page_renders_columns_money_and_links()
    {
        $wave = $this->seedNextYear();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('deployments.planning.horizon'))
            ->assertOk()
            ->assertSeeInOrder(['FY2027-28', 'FY2028-29', 'FY2029-30', 'FY2030-31'])
            ->assertSee(trans('admin/deployments/general.horizon_envelope'))
            ->assertSee('$4,500.00')
            ->assertSee('$3,000.00')
            ->assertSee('($1,500.00)')
            ->assertSee(route('deployment-waves.show', $wave), false)
            ->assertSee(route('deployments.planning', ['fiscal_year' => 'FY2030-31']), false)
            ->assertSee(route('reports.procurement.capital-request', ['fiscal_year' => 'FY2027-28']), false);
    }

    public function test_a_deployments_viewer_sees_devices_but_no_money()
    {
        $this->seedNextYear();
        $viewer = User::factory()->create(['permissions' => '{"deployments.view":"1"}']);

        $this->actingAs($viewer)
            ->get(route('deployments.planning.horizon'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.horizon_devices'))
            ->assertDontSee(trans('admin/deployments/general.horizon_envelope'))
            ->assertDontSee('$3,000.00')
            ->assertDontSee(route('reports.procurement.capital-request', ['fiscal_year' => 'FY2027-28']), false);
    }

    public function test_the_horizon_needs_deployments_access()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('deployments.planning.horizon'))
            ->assertForbidden();
    }

    public function test_the_planning_page_links_to_the_horizon()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('deployments.planning'))
            ->assertOk()
            ->assertSee(route('deployments.planning.horizon'), false);
    }
}
