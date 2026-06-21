<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\User;
use Tests\TestCase;

/**
 * The Refresh Forecast report's early-renewal mode: any criteria supplied
 * drive the candidate set (ANDed) and bypass the default end-of-life
 * window, so a subset of an active lease can be forecasted for an early
 * refresh long before its EOL date.
 */
class ForecastCriteriaTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function assetInCategory(string $tag, string $categoryName): Asset
    {
        $category = Category::factory()->create(['category_type' => 'asset', 'name' => $categoryName]);
        $model = AssetModel::factory()->create(['category_id' => $category->id]);
        $asset = Asset::factory()->create(['asset_tag' => $tag, 'model_id' => $model->id]);

        // Null the EOL date so the asset would be excluded by the default
        // EOL forecast — only the criteria path can surface it.
        Asset::query()->whereKey($asset->id)->update(['asset_eol_date' => null]);

        return $asset->refresh();
    }

    public function test_a_category_criterion_includes_only_matching_devices_ignoring_the_eol_window()
    {
        $laptop = $this->assetInCategory('EARLY-LAPTOP', 'Laptop');
        $desktop = $this->assetInCategory('EARLY-DESKTOP', 'Desktop');

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.forecast', [
                'fiscal_year' => 'all',
                'criteria' => [['field' => 'category', 'value' => 'Laptop']],
            ]))
            ->assertOk()
            ->assertSee('EARLY-LAPTOP')
            ->assertDontSee('EARLY-DESKTOP');
    }

    public function test_with_no_criteria_a_non_eol_device_is_not_listed()
    {
        $laptop = $this->assetInCategory('EARLY-LAPTOP', 'Laptop');

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.forecast', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertDontSee('EARLY-LAPTOP');
    }

    public function test_blank_and_unknown_criteria_rows_are_ignored()
    {
        $laptop = $this->assetInCategory('EARLY-LAPTOP', 'Laptop');

        // A blank value row and an unknown field must not throw and must not
        // narrow the result on their own; the real category row still applies.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.forecast', [
                'fiscal_year' => 'all',
                'criteria' => [
                    ['field' => 'category', 'value' => 'Laptop'],
                    ['field' => 'category', 'value' => ''],
                    ['field' => 'cf:_snipeit_not_a_real_column', 'value' => 'x'],
                ],
            ]))
            ->assertOk()
            ->assertSee('EARLY-LAPTOP');
    }
}
