<?php

namespace Tests\Feature\Consumables;

use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Consumable;
use App\Models\User;
// AssetModel + Category factories drive consumable category typing.
use Tests\TestCase;

class CompatibleModelsTest extends TestCase
{
    private function consumablePayload(Consumable $consumable, array $overrides = []): array
    {
        return array_merge([
            'name' => $consumable->name,
            'category_id' => $consumable->category_id,
            'qty' => $consumable->qty,
            'category_type' => 'consumable',
            'redirect_option' => 'index',
        ], $overrides);
    }

    private function consumable(): Consumable
    {
        // The consumable-category-type validation rule rejects categories
        // whose type isn't 'consumable', so pin one explicitly here.
        return Consumable::factory()->create([
            'category_id' => Category::factory()->consumableInkCategory()->create()->id,
        ]);
    }

    public function test_consumable_has_no_compatible_models_by_default()
    {
        $this->assertCount(0, $this->consumable()->compatibleModels);
    }

    public function test_updating_a_consumable_persists_compatible_models()
    {
        $consumable = $this->consumable();
        $models = AssetModel::factory()->count(2)->create();

        $user = User::factory()->createConsumables()->editConsumables()->create();
        $response = $this->actingAs($user)
            ->put(
                route('consumables.update', $consumable),
                $this->consumablePayload($consumable, [
                    'name' => 'CompatibleModelsTest-renamed',
                    'compatible_models' => $models->pluck('id')->all(),
                ])
            );
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $fresh = $consumable->fresh();
        // Sanity-check the controller ran end-to-end: the name change persisted.
        $this->assertEquals('CompatibleModelsTest-renamed', $fresh->name);
        $this->assertEqualsCanonicalizing(
            $models->pluck('id')->all(),
            $fresh->compatibleModels->pluck('id')->all()
        );
    }

    public function test_clearing_compatible_models_removes_the_pivot_rows()
    {
        $consumable = $this->consumable();
        $consumable->compatibleModels()->sync(AssetModel::factory()->count(2)->create()->pluck('id')->all());
        $this->assertCount(2, $consumable->compatibleModels);

        $response = $this->actingAs(User::factory()->createConsumables()->editConsumables()->create())
            ->put(
                route('consumables.update', $consumable),
                // Explicit empty array — submitting an empty multi-select still
                // clears the pivot table; the controller treats null and empty
                // array the same way (sync(array_filter([])) = sync([])).
                $this->consumablePayload($consumable, ['compatible_models' => []])
            );
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertCount(0, $consumable->fresh()->compatibleModels);
    }
}
