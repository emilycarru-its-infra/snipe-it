<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Asset;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

/**
 * Saves the asset edit form the way a browser does — every control the page
 * renders, each given a new value — and checks that each one reached the row.
 */
class EditAssetEveryFieldTest extends TestCase
{
    public function test_every_field_on_the_edit_form_saves(): void
    {
        $text = CustomField::factory()->create(['name' => 'Hostname Check']);
        $checkbox = CustomField::factory()->testCheckbox()->create();
        $radio = CustomField::factory()->testRadio()->create();

        $asset = Asset::factory()->hasMultipleCustomFields([$text, $checkbox, $radio])->create();
        $user = User::factory()->viewAssets()->editAssets()->create();

        $html = $this->actingAs($user)->get(route('hardware.edit', $asset))->assertOk()->getContent();
        preg_match('/<form id="create-form".*?<\/form>/s', $html, $form);
        preg_match_all('/\sname="([^"]+)"/', $form[0], $rendered);
        $rendered = array_unique($rendered[1]);

        $changes = [
            'company_id' => Company::factory()->create()->id,
            'asset_tags' => [1 => 'EVERY-FIELD-TAG'],
            'serials' => [1 => 'EVERYFIELDSERIAL'],
            'model_id' => $asset->model_id,
            'status_id' => Statuslabel::factory()->rtd()->create()->id,
            'notes' => 'Changed notes',
            'rtd_location_id' => Location::factory()->create()->id,
            'ownership_type' => Asset::OWNERSHIP_TYPES[1],
            'lease_usage' => Asset::LEASE_USAGES[1],
            'requestable' => '1',
            'name' => 'Changed name',
            'warranty_months' => '37',
            'expected_checkin' => '2031-02-03',
            'next_audit_date' => '2031-03-04',
            'byod' => '1',
            'order_number' => 'ORDER-CHANGED',
            'gl_code' => 'GL-CHANGED',
            'tracking_number' => 'TRACK-CHANGED',
            'purchase_date' => '2025-04-05',
            'asset_eol_date' => '2032-05-06',
            'supplier_id' => Supplier::factory()->create()->id,
            'lessor_id' => Supplier::factory()->create()->id,
            'purchase_cost' => '1234.56',
            $text->db_column => 'CHANGEDHOST',
            $checkbox->db_column => ['One', 'Two'],
            $radio->db_column => 'Two',
        ];

        // The page has to offer each of these, or nobody could have changed it.
        foreach (array_keys($changes) as $field) {
            $this->assertNotEmpty(
                preg_grep('/^'.preg_quote($field, '/').'(\[.*\])?$/', $rendered),
                "The edit form does not render [{$field}]."
            );
        }

        $this->actingAs($user)
            ->from(route('hardware.edit', $asset))
            ->put(route('hardware.update', $asset), $changes + ['redirect_option' => 'item'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('hardware.show', $asset));

        $saved = Asset::find($asset->id);
        $expected = [
            'asset_tag' => 'EVERY-FIELD-TAG',
            'serial' => 'EVERYFIELDSERIAL',
            'purchase_cost' => 1234.56,
            $checkbox->db_column => 'One, Two',
        ] + collect($changes)->except(['asset_tags', 'serials', 'purchase_cost', $checkbox->db_column])->all();

        foreach ($expected as $column => $value) {
            $actual = $saved->getRawOriginal($column);
            if (in_array($column, ['expected_checkin', 'next_audit_date', 'purchase_date', 'asset_eol_date'])) {
                $actual = substr((string) $actual, 0, 10);
            }
            $this->assertEquals($value, $actual, "[{$column}] did not save.");
        }
    }
}
