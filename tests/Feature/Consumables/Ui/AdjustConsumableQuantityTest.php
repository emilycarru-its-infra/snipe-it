<?php

namespace Tests\Feature\Consumables\Ui;

use App\Models\Actionlog;
use App\Models\Consumable;
use App\Models\User;
use Tests\TestCase;

class AdjustConsumableQuantityTest extends TestCase
{
    public function test_requires_update_permission()
    {
        $consumable = Consumable::factory()->create(['qty' => 0]);

        $this->actingAs(User::factory()->create())
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => 1])
            ->assertForbidden();
    }

    public function test_increments_quantity_by_delta()
    {
        $consumable = Consumable::factory()->create(['qty' => 2]);
        $user = User::factory()->editConsumables()->create();

        $response = $this->actingAs($user)
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => 1]);

        $response->assertOk()->assertJson(['status' => 'success', 'qty' => 3, 'remaining' => 3]);
        $this->assertEquals(3, $consumable->fresh()->qty);
    }

    public function test_decrements_quantity_by_delta()
    {
        $consumable = Consumable::factory()->create(['qty' => 2]);
        $user = User::factory()->editConsumables()->create();

        $this->actingAs($user)
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => -1])
            ->assertOk()->assertJson(['qty' => 1]);
        $this->assertEquals(1, $consumable->fresh()->qty);
    }

    public function test_sets_absolute_quantity()
    {
        $consumable = Consumable::factory()->create(['qty' => 0]);
        $user = User::factory()->editConsumables()->create();

        $this->actingAs($user)
            ->post(route('consumables.adjust-qty', $consumable), ['qty' => 12])
            ->assertOk()->assertJson(['qty' => 12]);
        $this->assertEquals(12, $consumable->fresh()->qty);
    }

    public function test_never_drops_below_what_is_checked_out()
    {
        $user = User::factory()->editConsumables()->create();
        $consumable = Consumable::factory()->create(['qty' => 2]);
        $consumable->users()->attach($consumable->id, ['consumable_id' => $consumable->id, 'assigned_to' => $user->id]);
        $consumable->users()->attach($consumable->id, ['consumable_id' => $consumable->id, 'assigned_to' => $user->id]);
        $this->assertEquals(2, $consumable->numCheckedOut());

        // Trying to go to 0 (or below 2 checked out) clamps to 2.
        $this->actingAs($user)
            ->post(route('consumables.adjust-qty', $consumable), ['delta' => -5])
            ->assertOk()->assertJson(['qty' => 2]);
        $this->assertEquals(2, $consumable->fresh()->qty);
    }

    public function test_change_is_recorded_in_the_activity_log()
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
