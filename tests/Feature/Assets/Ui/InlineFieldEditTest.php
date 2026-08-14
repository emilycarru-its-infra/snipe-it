<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\User;
use Tests\TestCase;

/**
 * Inline single-field edit from the asset detail view — both the native-column
 * endpoint (hardware.corefield.update) and the custom-field endpoint
 * (hardware.field.update). Covers the happy path plus the whitelist /
 * element-type / authorization guards.
 */
class InlineFieldEditTest extends TestCase
{
    // ---- Native columns: hardware.corefield.update -----------------------

    public function test_inline_core_field_edit_requires_edit_permission()
    {
        $asset = Asset::factory()->create();

        $this->actingAs(User::factory()->create())
            ->from(route('hardware.show', $asset))
            ->patch(route('hardware.corefield.update', $asset), ['field' => 'name', 'value' => 'Nope'])
            ->assertForbidden();
    }

    public function test_inline_core_field_edit_updates_a_whitelisted_column()
    {
        $asset = Asset::factory()->create(['name' => 'Old name']);

        $this->actingAs(User::factory()->editAssets()->create())
            ->patch(route('hardware.corefield.update', $asset), ['field' => 'name', 'value' => 'New name'])
            ->assertStatus(302);

        $this->assertEquals('New name', $asset->fresh()->name);
    }

    public function test_inline_core_field_edit_rejects_a_non_whitelisted_column()
    {
        // status_id joined the whitelist for the decommissioning cards, so
        // the canonical system-owned rejection example is assigned_to.
        $asset = Asset::factory()->create();
        $originalStatus = $asset->status_id;

        $this->actingAs(User::factory()->editAssets()->create())
            ->patch(route('hardware.corefield.update', $asset), ['field' => 'assigned_to', 'value' => '99999'])
            ->assertSessionHas('error');

        // And a whitelisted FK still refuses a row that does not exist.
        $this->actingAs(User::factory()->editAssets()->create())
            ->patch(route('hardware.corefield.update', $asset), ['field' => 'status_id', 'value' => '99999'])
            ->assertSessionHasErrors();

        $this->assertEquals($originalStatus, $asset->fresh()->status_id);
    }

    // ---- Custom fields: hardware.field.update ----------------------------

    private function assetWithCustomField(CustomField $field): Asset
    {
        $fieldset = CustomFieldset::factory()->create();
        $fieldset->fields()->attach($field, ['order' => 1, 'required' => false]);
        $model = AssetModel::factory()->create(['fieldset_id' => $fieldset->id]);

        return Asset::factory()->create(['model_id' => $model->id]);
    }

    public function test_inline_custom_field_edit_requires_edit_permission()
    {
        $field = CustomField::factory()->create();
        $asset = $this->assetWithCustomField($field);

        $this->actingAs(User::factory()->create())
            ->patch(route('hardware.field.update', $asset), ['field' => $field->db_column, 'value' => 'Nope'])
            ->assertForbidden();
    }

    public function test_inline_custom_field_edit_updates_a_text_field_on_the_fieldset()
    {
        $field = CustomField::factory()->create();
        $asset = $this->assetWithCustomField($field);

        $this->actingAs(User::factory()->editAssets()->create())
            ->patch(route('hardware.field.update', $asset), ['field' => $field->db_column, 'value' => '3.2GHz i9'])
            ->assertStatus(302);

        $this->assertEquals('3.2GHz i9', $asset->fresh()->{$field->db_column});
    }

    public function test_inline_custom_field_edit_rejects_a_field_not_on_the_fieldset()
    {
        $asset = $this->assetWithCustomField(CustomField::factory()->create());
        $strayField = CustomField::factory()->create();

        $this->actingAs(User::factory()->editAssets()->create())
            ->patch(route('hardware.field.update', $asset), ['field' => $strayField->db_column, 'value' => 'x'])
            ->assertSessionHas('error');

        $this->assertNull($asset->fresh()->{$strayField->db_column});
    }

    public function test_inline_custom_field_edit_accepts_a_valid_listbox_option()
    {
        $field = CustomField::factory()->create([
            'element' => 'listbox',
            'field_values' => "Red\nGreen\nBlue",
        ]);
        $asset = $this->assetWithCustomField($field);

        $this->actingAs(User::factory()->editAssets()->create())
            ->patch(route('hardware.field.update', $asset), ['field' => $field->db_column, 'value' => 'Green'])
            ->assertStatus(302);

        $this->assertEquals('Green', $asset->fresh()->{$field->db_column});
    }

    public function test_inline_custom_field_edit_rejects_an_off_list_option()
    {
        $field = CustomField::factory()->create([
            'element' => 'listbox',
            'field_values' => "Red\nGreen\nBlue",
        ]);
        $asset = $this->assetWithCustomField($field);

        $this->actingAs(User::factory()->editAssets()->create())
            ->patch(route('hardware.field.update', $asset), ['field' => $field->db_column, 'value' => 'Purple'])
            ->assertSessionHas('error');

        $this->assertNull($asset->fresh()->{$field->db_column});
    }

    public function test_lease_taxonomy_columns_edit_as_contained_dropdowns()
    {
        // Seed one asset carrying an existing Area value so the option list
        // (distinct fleet values) contains it.
        \App\Models\Asset::factory()->create(['lease_area' => 'Research']);
        $asset = \App\Models\Asset::factory()->create();
        $user = User::factory()->editAssets()->create();

        // A known Area value saves…
        $this->actingAs($user)
            ->patch(route('hardware.corefield.update', $asset), ['field' => 'lease_area', 'value' => 'Research'])
            ->assertSessionHas('success');
        $this->assertEquals('Research', $asset->fresh()->lease_area);

        // …an invented one is rejected, exactly like an off-list listbox value.
        $this->actingAs($user)
            ->patch(route('hardware.corefield.update', $asset), ['field' => 'lease_area', 'value' => 'MadeUpDept'])
            ->assertSessionHas('error');
        $this->assertEquals('Research', $asset->fresh()->lease_area);

        // Ownership and usage follow their fixed vocabularies.
        $this->actingAs($user)
            ->patch(route('hardware.corefield.update', $asset), ['field' => 'ownership_type', 'value' => 'Lease to Return'])
            ->assertSessionHas('success');
        $this->actingAs($user)
            ->patch(route('hardware.corefield.update', $asset), ['field' => 'lease_usage', 'value' => 'Rented'])
            ->assertSessionHas('error');
        $fresh = $asset->fresh();
        $this->assertEquals('Lease to Return', $fresh->ownership_type);
        $this->assertNull($fresh->lease_usage);
    }

    public function test_edit_form_carries_the_lease_taxonomy_dropdowns()
    {
        \App\Models\Asset::factory()->create(['lease_area' => 'Library']);
        $asset = \App\Models\Asset::factory()->create(['lease_area' => 'Library', 'lease_usage' => 'Shared']);
        $user = User::factory()->editAssets()->create();

        // The form renders all three selects with the fleet's options.
        $this->actingAs($user)
            ->get(route('hardware.edit', $asset))
            ->assertOk()
            ->assertSee('name="lease_area"', false)
            ->assertSee('name="lease_usage"', false)
            ->assertSee('name="ownership_type"', false)
            ->assertSee('Library');

        // Submitting the full form persists the taxonomy values.
        $this->actingAs($user)
            ->put(route('hardware.update', $asset), [
                'asset_tags' => [1 => $asset->asset_tag],
                'model_id' => $asset->model_id,
                'status_id' => $asset->status_id,
                'lease_area' => 'Library',
                'lease_usage' => 'Assigned',
                'ownership_type' => 'Purchased',
            ])
            ->assertStatus(302);

        $fresh = $asset->fresh();
        $this->assertEquals('Library', $fresh->lease_area);
        $this->assertEquals('Assigned', $fresh->lease_usage);
        $this->assertEquals('Purchased', $fresh->ownership_type);
    }
}
