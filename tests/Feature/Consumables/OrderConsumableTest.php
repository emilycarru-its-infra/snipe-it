<?php

namespace Tests\Feature\Consumables;

use App\Models\Category;
use App\Models\Consumable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

class OrderConsumableTest extends TestCase
{
    private function consumable(): Consumable
    {
        return Consumable::factory()->create([
            'category_id' => Category::factory()->consumableInkCategory()->create()->id,
            'purchase_cost' => 12.34,
        ]);
    }

    public function test_requires_checkout_permission_to_open_order_form()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('consumables.order.create', $this->consumable()))
            ->assertForbidden();
    }

    public function test_form_lists_existing_planned_orders()
    {
        $consumable = $this->consumable();
        $planned = Order::factory()->create(['order_number' => 'PLAN-2026-A', 'is_planned' => true]);
        Order::factory()->create(['order_number' => 'NOT-PLAN', 'is_planned' => false]);

        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->get(route('consumables.order.create', $consumable))
            ->assertOk()
            ->assertSee('PLAN-2026-A')
            // Realised orders shouldn't appear in the picker — only planned ones.
            ->assertDontSee('NOT-PLAN');
    }

    public function test_adding_to_an_existing_planned_order_creates_a_line_item()
    {
        $consumable = $this->consumable();
        $planned = Order::factory()->create(['is_planned' => true]);
        $user = User::factory()->checkoutConsumables()->create();

        $this->actingAs($user)
            ->post(route('consumables.order.store', $consumable), [
                'target' => 'existing',
                'order_id' => $planned->id,
                'quantity' => 3,
                'unit_cost' => 5.50,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('orders.show', $planned->id));

        $item = OrderItem::where('order_id', $planned->id)->first();
        $this->assertNotNull($item);
        $this->assertEquals(Consumable::class, $item->item_type);
        $this->assertEquals($consumable->id, $item->item_id);
        $this->assertEquals(3, $item->quantity);
        $this->assertEquals(5.50, (float) $item->unit_cost);
    }

    public function test_creating_a_new_planned_order_when_none_yet()
    {
        $consumable = $this->consumable();

        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->post(route('consumables.order.store', $consumable), [
                'target' => 'new',
                'new_order_number' => 'NEW-PLANNED-1',
                'fiscal_year' => 'FY2026-27',
                'quantity' => 2,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $order = Order::where('order_number', 'NEW-PLANNED-1')->first();
        $this->assertNotNull($order);
        $this->assertTrue((bool) $order->is_planned);
        $this->assertEquals('FY2026-27', $order->fiscal_year);

        $item = OrderItem::where('order_id', $order->id)->first();
        $this->assertEquals(Consumable::class, $item->item_type);
        $this->assertEquals(2, $item->quantity);
        // Falls back to the consumable's purchase_cost when no explicit unit_cost.
        $this->assertEquals(12.34, (float) $item->unit_cost);
    }

    public function test_cannot_target_a_realised_order_by_id()
    {
        $consumable = $this->consumable();
        $realised = Order::factory()->create(['is_planned' => false]);

        // The form picker only offers planned orders, but the request body
        // could be tampered with. The store action re-checks the scope —
        // Order::planned()->findOrFail throws, and Snipe's exception
        // handler redirects ModelNotFoundException to the model's index.
        $this->actingAs(User::factory()->checkoutConsumables()->create())
            ->post(route('consumables.order.store', $consumable), [
                'target' => 'existing',
                'order_id' => $realised->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('orders.index'));

        $this->assertEquals(0, OrderItem::where('order_id', $realised->id)->count());
    }
}
