<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

/**
 * The asset view's edit mode: Update opens every field in place and one save
 * sends whatever changed to hardware.fields.update.
 */
class EditModeTest extends TestCase
{
    public function test_edit_mode_save_requires_edit_permission(): void
    {
        $asset = Asset::factory()->create(['name' => 'Before']);

        $this->actingAs(User::factory()->viewAssets()->create())
            ->patchJson(route('hardware.fields.update', $asset), ['core' => ['name' => 'After']])
            ->assertForbidden();

        $this->assertSame('Before', $asset->refresh()->name);
    }

    public function test_edit_mode_saves_native_and_custom_fields_together(): void
    {
        $text = CustomField::factory()->create();
        $checkbox = CustomField::factory()->testCheckbox()->create();
        $asset = Asset::factory()->hasMultipleCustomFields([$text, $checkbox])->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs(User::factory()->viewAssets()->editAssets()->create())
            ->patchJson(route('hardware.fields.update', $asset), [
                'core' => [
                    'name' => 'Edited in place',
                    'asset_tag' => 'IN-PLACE-1',
                    'serial' => 'INPLACESERIAL',
                    'purchase_date' => '2025-04-05',
                    'purchase_cost' => '1234.56',
                    'warranty_months' => '24',
                    'supplier_id' => $supplier->id,
                    'byod' => '1',
                    'requestable' => '1',
                    'notes' => 'Changed on the page',
                    'expected_checkin' => '',
                ],
                'custom' => [
                    $text->db_column => 'HOST-01',
                    $checkbox->db_column => ['One', 'Three'],
                ],
            ])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $asset->refresh();
        $this->assertSame('Edited in place', $asset->name);
        $this->assertSame('IN-PLACE-1', $asset->asset_tag);
        $this->assertSame('INPLACESERIAL', $asset->serial);
        $this->assertSame('2025-04-05', $asset->purchase_date->format('Y-m-d'));
        $this->assertEquals(1234.56, $asset->purchase_cost);
        $this->assertEquals(24, $asset->warranty_months);
        $this->assertEquals($supplier->id, $asset->supplier_id);
        $this->assertEquals(1, $asset->byod);
        $this->assertEquals(1, $asset->requestable);
        $this->assertSame('Changed on the page', $asset->notes);
        $this->assertNull($asset->expected_checkin);
        $this->assertSame('HOST-01', $asset->{$text->db_column});
        $this->assertSame('One, Three', $asset->{$checkbox->db_column});
    }

    public function test_edit_mode_saves_nothing_when_one_field_is_refused(): void
    {
        $asset = Asset::factory()->create(['name' => 'Before']);

        $this->actingAs(User::factory()->viewAssets()->editAssets()->create())
            ->patchJson(route('hardware.fields.update', $asset), [
                'core' => [
                    'name' => 'After',
                    'status_id' => 999999,
                    'ownership_type' => 'Not a real type',
                    'created_at' => '2001-01-01',
                ],
                'custom' => ['_snipeit_not_on_this_fieldset_1' => 'x'],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => [
                'core.status_id', 'core.ownership_type', 'core.created_at', 'custom._snipeit_not_on_this_fieldset_1',
            ]])
            ->assertJsonMissingPath('errors.core.name');

        $this->assertSame('Before', $asset->refresh()->name);
    }

    public function test_asset_view_offers_every_edit_form_field_in_place(): void
    {
        $asset = Asset::factory()->create();

        $html = $this->actingAs(User::factory()->viewAssets()->editAssets()->create())
            ->get(route('hardware.show', $asset))
            ->assertOk()
            ->getContent();

        foreach ([
            'name', 'asset_tag', 'serial', 'model_id', 'status_id', 'notes', 'company_id',
            'purchase_date', 'purchase_cost', 'warranty_months', 'supplier_id', 'lessor_id',
            'ownership_type', 'order_number', 'expected_checkin', 'next_audit_date', 'byod', 'requestable',
        ] as $column) {
            $this->assertStringContainsString('data-kind="core" data-column="'.$column.'"', $html, "[{$column}] has no in-place editor.");
        }
    }
}
