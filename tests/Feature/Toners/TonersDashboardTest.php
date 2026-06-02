<?php

namespace Tests\Feature\Toners;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Consumable;
use App\Models\Manufacturer;
use App\Models\User;
use Tests\TestCase;

class TonersDashboardTest extends TestCase
{
    public function test_requires_permission_to_view_toners()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('toners.index'))
            ->assertForbidden();
    }

    public function test_empty_state_renders_when_no_consumables_have_compatible_models()
    {
        // Even with a printer model and consumables present, the dashboard
        // is empty until at least one consumable links to a model.
        $manufacturer = Manufacturer::factory()->create(['name' => 'Ricoh']);
        AssetModel::factory()->for($manufacturer)->create(['name' => 'IM C300']);
        Consumable::factory()->create(['name' => 'IM C300 Black Toner']);

        $this->actingAs(User::factory()->viewConsumables()->create())
            ->get(route('toners.index'))
            ->assertOk()
            ->assertDontSee('IM C300')
            ->assertSee(trans('admin/toners/general.toners'));
    }

    public function test_models_with_compatible_consumables_render_as_cards()
    {
        $ricoh = Manufacturer::factory()->create(['name' => 'Ricoh']);
        $im300 = AssetModel::factory()->for($ricoh)->create(['name' => 'IM C300']);
        Asset::factory()->count(2)->create(['model_id' => $im300->id]);

        $blackToner = Consumable::factory()->create(['name' => 'IM C300 Black Toner', 'qty' => 4]);
        $cyanToner = Consumable::factory()->create(['name' => 'IM C300 Cyan Toner', 'qty' => 2]);
        $blackToner->compatibleModels()->sync([$im300->id]);
        $cyanToner->compatibleModels()->sync([$im300->id]);

        $this->actingAs(User::factory()->viewConsumables()->create())
            ->get(route('toners.index'))
            ->assertOk()
            ->assertSee('Ricoh')
            ->assertSee('IM C300')
            // The card surfaces how many physical printers of this model
            // are in service — useful for ranking stock priorities.
            ->assertSee('2 printers')
            ->assertSee('IM C300 Black Toner')
            ->assertSee('IM C300 Cyan Toner');
    }

    public function test_low_stock_consumables_get_warning_class()
    {
        $ricoh = Manufacturer::factory()->create();
        $model = AssetModel::factory()->for($ricoh)->create();

        // Below the minimum threshold — should land in the yellow band.
        $low = Consumable::factory()->create(['qty' => 1, 'min_amt' => 2]);
        $low->compatibleModels()->sync([$model->id]);

        // Empty — red band.
        $empty = Consumable::factory()->create(['qty' => 0, 'min_amt' => 0]);
        $empty->compatibleModels()->sync([$model->id]);

        $response = $this->actingAs(User::factory()->viewConsumables()->create())
            ->get(route('toners.index'))
            ->assertOk();

        // The colour band now lives on the inline quantity stepper.
        $response->assertSee('qty-stepper--yellow', false);
        $response->assertSee('qty-stepper--red', false);
    }
}
