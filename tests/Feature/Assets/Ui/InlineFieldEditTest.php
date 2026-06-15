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
        $asset = Asset::factory()->create();
        $originalStatus = $asset->status_id;

        $this->actingAs(User::factory()->editAssets()->create())
            ->patch(route('hardware.corefield.update', $asset), ['field' => 'status_id', 'value' => '99999'])
            ->assertSessionHas('error');

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

    public function test_inline_custom_field_edit_rejects_non_text_elements()
    {
        $field = CustomField::factory()->testCheckbox()->create();
        $asset = $this->assetWithCustomField($field);

        $this->actingAs(User::factory()->editAssets()->create())
            ->patch(route('hardware.field.update', $asset), ['field' => $field->db_column, 'value' => 'tampered'])
            ->assertSessionHas('error');

        $this->assertNull($asset->fresh()->{$field->db_column});
    }
}
