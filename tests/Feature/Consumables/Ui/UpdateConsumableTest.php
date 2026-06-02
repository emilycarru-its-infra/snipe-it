<?php

namespace Tests\Feature\Consumables\Ui;

use App\Models\Actionlog;
use App\Models\Category;
use App\Models\Company;
use App\Models\Consumable;
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
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => 1])
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
            ->post(route('consumables.adjust-qty', $consumable), ['qty' => 12])
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

    public function test_adjust_quantity_is_recorded_in_the_activity_log()
    {
        $consumable = Consumable::factory()->create(['qty' => 0]);
        $user = User::factory()->editConsumables()->create();

        $this->actingAs($user)
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => 1])
            ->assertOk();

        // ConsumableObserver logs an 'update' action with the changed qty,
        // attributed to the acting user — the traceability Snipe already has.
        $log = Actionlog::where('item_type', Consumable::class)
            ->where('item_id', $consumable->id)
            ->where('action_type', 'update')
            ->latest('id')->first();

        $this->assertNotNull($log, 'A qty nudge should write an update action log entry');
        $this->assertEquals($user->id, $log->created_by);
        $this->assertStringContainsString('qty', (string) $log->log_meta);
    }
}
