<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Asset;
use App\Models\User;
use Tests\TestCase;

/**
 * The Procurement card carries the native purchase and lease fields, so it
 * must render on every asset view — including models whose fieldset has no
 * custom fields left in the procurement group (all of them, since the F2
 * migration dropped the lease custom-field twins) and models with no
 * fieldset at all.
 */
class AssetViewProcurementCardTest extends TestCase
{
    public function test_the_procurement_card_renders_without_procurement_custom_fields()
    {
        $this->actingAs(User::factory()->superuser()->create());

        $asset = Asset::factory()->create([
            'po_number' => 'P0025420',
            'lease_contract_id' => 'ECI20221001',
            'ownership_type' => 'Lease to Return',
        ]);

        $this->get(route('hardware.show', $asset))
            ->assertOk()
            ->assertSee(trans('general.po_number'))
            ->assertSee('P0025420')
            ->assertSee('ECI20221001');
    }
}
