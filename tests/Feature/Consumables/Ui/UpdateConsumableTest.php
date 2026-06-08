<?php

namespace Tests\Feature\Consumables\Ui;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Company;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

class UpdateConsumableTest extends TestCase
{
    public function test_requires_permission_to_see_edit_consumable_page()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('consumables.edit', Consumable::factory()->create()))
            ->assertForbidden();
    }

    public function test_does_not_show_edit_consumable_page_from_another_company()
    {
        $this->settings->enableMultipleFullCompanySupport();

        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $consumableForCompanyA = Consumable::factory()->for($companyA)->create();
        $userForCompanyB = User::factory()->editConsumables()->for($companyB)->create();

        $this->actingAs($userForCompanyB)
            ->get(route('consumables.edit', $consumableForCompanyA))
            ->assertRedirect(route('consumables.index'));
    }

    public function test_edit_consumable_page_renders()
    {
        $this->actingAs(User::factory()->editConsumables()->create())
            ->get(route('consumables.edit', Consumable::factory()->create()))
            ->assertOk()
            ->assertViewIs('consumables.edit');
    }

    public function test_cannot_update_consumable_belonging_to_another_company()
    {
        $this->settings->enableMultipleFullCompanySupport();

        [$companyA, $companyB] = Company::factory()->count(2)->create();

        $consumableForCompanyA = Consumable::factory()->for($companyA)->create();
        $userForCompanyB = User::factory()->editConsumables()->for($companyB)->create();

        $this->actingAs($userForCompanyB)
            ->put(route('consumables.update', $consumableForCompanyA), [
                //
            ])
            ->assertStatus(302);
    }

    public function test_cannot_set_quantity_to_amount_lower_than_what_is_checked_out()
    {
        $user = User::factory()->createConsumables()->editConsumables()->create();
        $consumable = Consumable::factory()->create(['qty' => 2]);

        $consumable->users()->attach($consumable->id, ['consumable_id' => $consumable->id, 'assigned_to' => $user->id]);
        $consumable->users()->attach($consumable->id, ['consumable_id' => $consumable->id, 'assigned_to' => $user->id]);

        $this->assertEquals(2, $consumable->numCheckedOut());

        $this->actingAs($user)
            ->put(route('consumables.update', $consumable->id), [
                'qty' => 1,
                'redirect_option' => 'index',
                'category_type' => 'consumable',
            ])
            ->assertSessionHasErrors('qty');

    }

    public function test_can_update_consumable()
    {
        $consumable = Consumable::factory()->create();

        $data = [
            'company_id' => Company::factory()->create()->id,
            'name' => 'My Consumable',
            'category_id' => Category::factory()->consumableInkCategory()->create()->id,
            'supplier_id' => Supplier::factory()->create()->id,
            'manufacturer_id' => Manufacturer::factory()->create()->id,
            'location_id' => Location::factory()->create()->id,
            'model_number' => '8765',
            'item_no' => '5678',
            'order_number' => '908',
            'purchase_date' => '2024-12-05',
            'purchase_cost' => '89.45',
            'qty' => '9',
            'min_amt' => '7',
            'notes' => 'Some Notes',
        ];

        $this->actingAs(User::factory()->createConsumables()->editConsumables()->create())
            ->put(route('consumables.update', $consumable), $data + [
                'redirect_option' => 'index',
                'category_type' => 'consumable',
            ])
            ->assertRedirect(route('consumables.index'));

        $this->assertDatabaseHas('consumables', $data);
    }

    public function test_toner_quantity_cannot_be_changed_via_the_edit_form()
    {
        $model = AssetModel::factory()->create();
        $consumable = Consumable::factory()->create(['qty' => 5]);
        $consumable->compatibleModels()->sync([$model->id]);

        $this->actingAs(User::factory()->createConsumables()->editConsumables()->create())
            ->put(route('consumables.update', $consumable), [
                'company_id' => Company::factory()->create()->id,
                'name' => 'Locked Toner',
                'category_id' => Category::factory()->consumableInkCategory()->create()->id,
                'qty' => 99,
                'min_amt' => 1,
                'redirect_option' => 'index',
                'category_type' => 'consumable',
            ])
            ->assertRedirect(route('consumables.index'));

        // qty is locked for toners (compatible-model consumables) — the
        // submitted 99 is ignored; stock moves only via checkin/checkout.
        $this->assertEquals(5, $consumable->fresh()->qty);
        // ...but other fields still update normally.
        $this->assertEquals('Locked Toner', $consumable->fresh()->name);
    }

    public function test_non_toner_quantity_can_still_be_changed_via_the_edit_form()
    {
        $consumable = Consumable::factory()->create(['qty' => 5]);

        $this->actingAs(User::factory()->createConsumables()->editConsumables()->create())
            ->put(route('consumables.update', $consumable), [
                'company_id' => Company::factory()->create()->id,
                'name' => 'Regular Consumable',
                'category_id' => Category::factory()->consumableInkCategory()->create()->id,
                'qty' => 12,
                'min_amt' => 1,
                'redirect_option' => 'index',
                'category_type' => 'consumable',
            ])
            ->assertRedirect(route('consumables.index'));

        $this->assertEquals(12, $consumable->fresh()->qty);
    }

    public function test_adjust_quantity_requires_update_permission()
    {
        $consumable = Consumable::factory()->create(['qty' => 0]);

        $this->actingAs(User::factory()->create())
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => 1])
            ->assertForbidden();
    }

    public function test_adjust_quantity_increments_by_delta()
    {
        $consumable = Consumable::factory()->create(['qty' => 2]);

        $this->actingAs(User::factory()->editConsumables()->create())
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => 1, 'req_number' => 'PO-1001'])
            ->assertOk()
            ->assertJson(['status' => 'success', 'qty' => 3, 'remaining' => 3]);

        $this->assertEquals(3, $consumable->fresh()->qty);
    }

    public function test_adjust_quantity_decrements_by_delta()
    {
        $consumable = Consumable::factory()->create(['qty' => 2]);

        $this->actingAs(User::factory()->editConsumables()->create())
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => -1])
            ->assertOk()
            ->assertJson(['qty' => 1]);

        $this->assertEquals(1, $consumable->fresh()->qty);
    }

    public function test_adjust_quantity_sets_absolute_value()
    {
        $consumable = Consumable::factory()->create(['qty' => 0]);

        $this->actingAs(User::factory()->editConsumables()->create())
            ->post(route('consumables.adjust-qty', $consumable), ['qty' => 12, 'req_number' => 'PO-1002'])
            ->assertOk()
            ->assertJson(['qty' => 12]);

        $this->assertEquals(12, $consumable->fresh()->qty);
    }

    public function test_adjust_quantity_never_drops_below_checked_out()
    {
        $user = User::factory()->editConsumables()->create();
        $consumable = Consumable::factory()->create(['qty' => 2]);
        $consumable->users()->attach($consumable->id, ['consumable_id' => $consumable->id, 'assigned_to' => $user->id]);
        $consumable->users()->attach($consumable->id, ['consumable_id' => $consumable->id, 'assigned_to' => $user->id]);
        $this->assertEquals(2, $consumable->numCheckedOut());

        $this->actingAs($user)
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => -5])
            ->assertOk()
            ->assertJson(['qty' => 2]);

        $this->assertEquals(2, $consumable->fresh()->qty);
    }

    public function test_adjust_quantity_up_is_recorded_as_a_checkin()
    {
        $consumable = Consumable::factory()->create(['qty' => 0]);
        $user = User::factory()->editConsumables()->create();

        $this->actingAs($user)
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => 1, 'req_number' => 'PO-7788'])
            ->assertOk();

        // Units arriving = a first-class 'checkin' (qty received), attributed
        // to the acting user — reads like an asset checkin, not a field edit.
        $log = Actionlog::where('item_type', Consumable::class)
            ->where('item_id', $consumable->id)
            ->where('action_type', 'checkin from')
            ->latest('id')->first();

        $this->assertNotNull($log, 'A stock increase should write a checkin action log entry');
        $this->assertEquals($user->id, $log->created_by);
        $this->assertEquals(1, (int) $log->quantity);

        // The cited PO / Req # is recorded in the history note for traceability.
        $this->assertStringContainsString('PO-7788', (string) $log->note);

        // ...and NOT a generic 'update' row (the observer is suppressed).
        $this->assertDatabaseMissing('action_logs', [
            'item_type' => Consumable::class,
            'item_id' => $consumable->id,
            'action_type' => 'update',
        ]);
    }

    public function test_adjust_quantity_up_is_rejected_without_a_source()
    {
        $consumable = Consumable::factory()->create(['qty' => 5]);

        $this->actingAs(User::factory()->editConsumables()->create())
            ->postJson(route('consumables.adjust-qty', $consumable), ['delta' => 1])
            ->assertStatus(422);

        // Stock is untouched when the restock cites no PO / Req #.
        $this->assertEquals(5, $consumable->fresh()->qty);
    }

    public function test_adjust_quantity_down_does_not_require_a_source()
    {
        // Decreases/corrections are not restocks, so they need no source.
        $consumable = Consumable::factory()->create(['qty' => 5]);

        $this->actingAs(User::factory()->editConsumables()->create())
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => -1])
            ->assertOk()
            ->assertJson(['qty' => 4]);
    }

    public function test_stepper_is_frozen_when_all_compatible_printers_are_out_of_circulation()
    {
        $model = AssetModel::factory()->create();
        // A printer sitting in storage is left unassigned — out of circulation.
        Asset::factory()->create(['model_id' => $model->id, 'assigned_to' => null]);
        $consumable = Consumable::factory()->create(['qty' => 3]);
        $consumable->compatibleModels()->sync([$model->id]);

        $user = User::factory()->editConsumables()->checkoutConsumables()->create();

        // Restock is blocked even with a valid PO.
        $this->actingAs($user)
            ->postJson(route('consumables.adjust-qty', $consumable), ['delta' => 1, 'req_number' => 'PO-1'])
            ->assertStatus(422);
        $this->assertEquals(3, $consumable->fresh()->qty);

        // ...and so is recording usage.
        $printer = Asset::factory()->create(['model_id' => $model->id, 'assigned_to' => null]);
        $this->actingAs($user)
            ->postJson(route('consumables.consume', $consumable), ['asset_id' => $printer->id])
            ->assertStatus(422);
        $this->assertSame(0, $consumable->fresh()->numCheckedOut());
    }

    public function test_stepper_is_active_when_a_compatible_printer_is_in_circulation()
    {
        $model = AssetModel::factory()->create();
        // Checked out to a user => in circulation => stepper stays usable.
        Asset::factory()->assignedToUser()->create(['model_id' => $model->id]);
        $consumable = Consumable::factory()->create(['qty' => 3]);
        $consumable->compatibleModels()->sync([$model->id]);

        $this->actingAs(User::factory()->editConsumables()->create())
            ->postJson(route('consumables.adjust-qty', $consumable), ['delta' => 1, 'req_number' => 'PO-9'])
            ->assertOk()
            ->assertJson(['qty' => 4]);
    }

    public function test_non_printer_consumable_is_never_frozen()
    {
        // No compatible models == not a printer toner; never frozen.
        $consumable = Consumable::factory()->create(['qty' => 2]);

        $this->actingAs(User::factory()->editConsumables()->create())
            ->postJson(route('consumables.adjust-qty', $consumable), ['delta' => 1, 'req_number' => 'PO-3'])
            ->assertOk()
            ->assertJson(['qty' => 3]);
    }

    public function test_compatible_printers_lists_assets_of_compatible_models()
    {
        $model = AssetModel::factory()->create();
        $printer = Asset::factory()->create(['model_id' => $model->id, 'name' => 'Front Desk Printer']);
        $consumable = Consumable::factory()->create();
        $consumable->compatibleModels()->sync([$model->id]);

        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->getJson(route('consumables.compatible-printers', $consumable))
            ->assertOk()
            ->assertJsonPath('printers.0.id', $printer->id);
    }

    public function test_consume_checks_out_one_to_printer_and_records_gl()
    {
        $model = AssetModel::factory()->create();
        // In circulation (checked out) so the stepper isn't frozen.
        $printer = Asset::factory()->assignedToUser()->create(['model_id' => $model->id, 'gl_code' => 'GL-1234']);
        $consumable = Consumable::factory()->create(['qty' => 3, 'purchase_cost' => 80]);
        $consumable->compatibleModels()->sync([$model->id]);

        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->postJson(route('consumables.consume', $consumable), ['asset_id' => $printer->id])
            ->assertOk()
            ->assertJsonPath('remaining', 2);

        $this->assertEquals(1, $consumable->fresh()->numCheckedOut());
        $this->assertSame(1, ConsumableTransaction::where('consumable_id', $consumable->id)
            ->where('asset_id', $printer->id)->where('gl_code', 'GL-1234')->count());

        // History reads like an asset checkout: action 'checkout', target the
        // printer, quantity 1 — not a bare qty field edit.
        $log = Actionlog::where('item_type', Consumable::class)
            ->where('item_id', $consumable->id)
            ->where('action_type', 'checkout')
            ->latest('id')->first();
        $this->assertNotNull($log, 'Consuming a cartridge should write a checkout action log entry');
        $this->assertEquals($printer->id, $log->target_id);
        $this->assertEquals(Asset::class, $log->target_type);
        $this->assertEquals(1, (int) $log->quantity);
    }

    public function test_consume_requires_checkout_permission()
    {
        $printer = Asset::factory()->create();
        $consumable = Consumable::factory()->create(['qty' => 2]);

        $this->actingAs(User::factory()->editConsumables()->create())
            ->postJson(route('consumables.consume', $consumable), ['asset_id' => $printer->id])
            ->assertForbidden();
    }

    public function test_consume_blocked_when_none_remaining()
    {
        $printer = Asset::factory()->create();
        $consumable = Consumable::factory()->create(['qty' => 0]);

        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->postJson(route('consumables.consume', $consumable), ['asset_id' => $printer->id])
            ->assertStatus(422);

        $this->assertSame(0, ConsumableTransaction::where('consumable_id', $consumable->id)->count());
    }
}
