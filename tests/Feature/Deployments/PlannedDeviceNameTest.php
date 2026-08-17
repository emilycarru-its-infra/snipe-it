<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use App\Models\User;
use Tests\TestCase;

/**
 * What a planning row is called before anything is ordered.
 *
 * A row that replaces a device carries that device's model in `model_id` —
 * the successor has no model of its own until somebody buys one. The costing
 * already resolves the successor through the catalog, so the name has to come
 * from the same place, or a refresh advertises the five-year-old machine it
 * is replacing as the machine it is replacing it with.
 */
class PlannedDeviceNameTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function plannedItem(?CatalogItem $catalog): DeploymentItem
    {
        DeploymentStage::firstOrCreate(['slug' => 'planned'], ['name' => 'Planned']);

        $incumbentModel = AssetModel::factory()->create([
            'name' => 'MacBook Pro (14-inch, 2021)',
            'refresh_catalog_item_id' => $catalog?->id,
        ]);
        $incumbent = Asset::factory()->create(['model_id' => $incumbentModel->id, 'asset_tag' => 'OLD-1']);

        $wave = DeploymentWave::create(['name' => 'Faculty refresh', 'fiscal_year' => 'FY2026-27']);

        return DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $incumbent->id,
            'model_id' => $incumbentModel->id,
            'stage_id' => DeploymentStage::where('slug', 'planned')->value('id'),
        ]);
    }

    public function test_a_planned_device_is_named_by_the_catalog_item_it_refreshes_to()
    {
        $catalog = CatalogItem::create([
            'name' => 'MacBook Pro | 14" | M5 | 16GB | 1TB | Silver',
            'category' => 'Laptops',
            'estimated_cost' => 2700,
            'price_type' => 'estimate',
            'product_type' => 'standard',
            'is_active' => true,
        ]);

        $item = $this->plannedItem($catalog);

        $this->assertSame('MacBook Pro | 14" | M5 | 16GB | 1TB | Silver', $item->plannedDeviceLabel());
        $this->assertSame('MacBook Pro | 14" | M5 | 16GB | 1TB | Silver', $item->deviceLabel());

        $this->actingAs($this->superuser())
            ->get(route('deployment-waves.show', $item->wave))
            ->assertOk()
            ->assertSee('MacBook Pro | 14&quot; | M5 | 16GB | 1TB | Silver', false);
    }

    public function test_an_unmapped_model_still_reads_as_something()
    {
        // A gap in the catalog mapping should show the stale name, not a
        // blank — a dash would read as "nothing planned", which is wrong.
        $item = $this->plannedItem(null);

        $this->assertSame('MacBook Pro (14-inch, 2021)', $item->plannedDeviceLabel());
    }
}
