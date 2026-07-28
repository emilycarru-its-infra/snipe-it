<?php

namespace Tests\Feature\Store;

use App\Models\CatalogItem;
use App\Models\Requisition;
use App\Models\StoreOrder;
use App\Models\User;
use Tests\TestCase;

/**
 * The store funnel end to end: browse → order → queue → approve → pull
 * into a requisition — and the boundaries between the two sides. The
 * storefront is for everyone; the queue is procurement's.
 */
class StoreFunnelTest extends TestCase
{
    /** A user with no procurement permissions at all. */
    private function endUser(): User
    {
        return User::factory()->create();
    }

    private function procurement(): User
    {
        return User::factory()->superuser()->create();
    }

    private function shelfItem(array $overrides = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'name' => 'MacBook Pro | 14" | M5 | 16GB | 1TB',
            'category' => 'Laptops',
            'product_type' => 'standard',
            'vendor_sku' => '854413',
            'unit_cost' => 2658.77,
            'price_type' => 'quoted',
            'show_in_store' => true,
        ], $overrides));
    }

    public function test_any_user_can_browse_and_only_shelf_items_show()
    {
        $shown = $this->shelfItem();
        $hidden = $this->shelfItem(['name' => 'Not on the shelf', 'vendor_sku' => '999', 'show_in_store' => false]);

        $response = $this->actingAs($this->endUser())->get(route('store.index'))->assertOk();

        // Names carry quotes (14"), so match the escaped form Blade emits.
        $response->assertSee($shown->name);
        $response->assertDontSee($hidden->name);
    }

    public function test_placing_an_order_snapshots_the_catalog_price()
    {
        $item = $this->shelfItem();
        $user = $this->endUser();

        $this->actingAs($user)
            ->post(route('store.orders.store'), [
                'notes' => 'For the animation lab',
                'items' => [['catalog_item_id' => $item->id, 'quantity' => 3]],
            ])
            ->assertRedirect(route('store.orders'));

        $order = StoreOrder::first();

        $this->assertSame($user->id, (int) $order->user_id);
        $this->assertSame('pending', $order->status);
        $this->assertEqualsWithDelta(7976.31, $order->total(), 0.001);

        // The catalog moves on; the order must not.
        $item->update(['unit_cost' => 9999]);
        $this->assertEqualsWithDelta(7976.31, $order->fresh()->total(), 0.001);
    }

    public function test_the_client_cannot_set_its_own_price()
    {
        $item = $this->shelfItem();

        $this->actingAs($this->endUser())
            ->post(route('store.orders.store'), [
                'items' => [['catalog_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 0.01]],
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(2658.77, (float) StoreOrder::first()->items->first()->unit_cost, 0.001);
    }

    public function test_an_item_hidden_from_the_store_cannot_be_ordered()
    {
        $hidden = $this->shelfItem(['show_in_store' => false]);

        $this->actingAs($this->endUser())
            ->post(route('store.orders.store'), [
                'items' => [['catalog_item_id' => $hidden->id, 'quantity' => 1]],
            ])
            ->assertRedirect(route('store.index'));

        $this->assertSame(0, StoreOrder::count());
    }

    public function test_users_see_only_their_own_orders_and_can_cancel_only_pending_ones()
    {
        $item = $this->shelfItem();
        $alice = $this->endUser();
        $bob = $this->endUser();

        $this->actingAs($alice)->post(route('store.orders.store'), [
            'notes' => 'Alice needs a laptop',
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();

        // Bob neither sees Alice's order nor can he cancel it.
        $this->actingAs($bob)->get(route('store.orders'))->assertOk()->assertDontSee('Alice needs a laptop', false);
        $this->actingAs($bob)->post(route('store.orders.cancel', $order->id))->assertForbidden();

        // Alice can — while it is still pending.
        $this->actingAs($alice)->post(route('store.orders.cancel', $order->id))->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_the_procurement_side_is_gated()
    {
        $user = $this->endUser();

        $this->actingAs($user)->get(route('procurement.index'))->assertForbidden();
        $this->actingAs($user)->get(route('procurement.queue'))->assertForbidden();
        $this->actingAs($user)->get(route('procurement.store-admin'))->assertForbidden();
        $this->actingAs($user)->post(route('procurement.queue.pull'), ['title' => 'x', 'orders' => [1]])->assertForbidden();
    }

    public function test_approve_and_pull_lands_the_order_on_a_requisition()
    {
        $item = $this->shelfItem();
        $requester = $this->endUser();
        $staff = $this->procurement();

        $this->actingAs($requester)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 2]],
        ]);
        $order = StoreOrder::first();

        $this->actingAs($staff)
            ->post(route('procurement.queue.decide', $order->id), ['decision' => 'approved', 'decision_notes' => 'Budget fits.'])
            ->assertRedirect();

        $this->assertSame('approved', $order->fresh()->status);

        $this->actingAs($staff)
            ->post(route('procurement.queue.pull'), ['title' => 'Store orders — week 31', 'orders' => [$order->id]])
            ->assertRedirect();

        $order->refresh();
        $requisition = Requisition::with('items')->first();

        $this->assertSame('ordered', $order->status);
        $this->assertSame($requisition->id, (int) $order->requisition_id);
        $this->assertCount(1, $requisition->items);
        $this->assertSame(2, $requisition->items->first()->quantity);
        $this->assertStringContainsString($requester->username, $requisition->items->first()->notes);
        $this->assertEqualsWithDelta($order->total(), $requisition->subtotal(), 0.001);

        // Requester-facing status follows the chain: no PO yet → processing.
        $this->assertSame('processing', $order->displayStatus());
    }

    public function test_a_declined_order_carries_the_reason_back_to_the_requester()
    {
        $item = $this->shelfItem();
        $requester = $this->endUser();

        $this->actingAs($requester)->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();

        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.decide', $order->id), [
                'decision' => 'declined',
                'decision_notes' => 'Out of cycle — ask again in September.',
            ])
            ->assertRedirect();

        $this->actingAs($requester)
            ->get(route('store.orders'))
            ->assertOk()
            ->assertSee('Out of cycle', false);
    }

    public function test_a_decided_order_cannot_be_decided_again()
    {
        $item = $this->shelfItem();
        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();
        $staff = $this->procurement();

        $this->actingAs($staff)->post(route('procurement.queue.decide', $order->id), ['decision' => 'declined']);
        $this->actingAs($staff)->post(route('procurement.queue.decide', $order->id), ['decision' => 'approved']);

        $this->assertSame('declined', $order->fresh()->status);
    }

    public function test_only_approved_orders_can_be_pulled()
    {
        $item = $this->shelfItem();
        $this->actingAs($this->endUser())->post(route('store.orders.store'), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);
        $order = StoreOrder::first();

        $this->actingAs($this->procurement())
            ->post(route('procurement.queue.pull'), ['title' => 'Sneaky pull', 'orders' => [$order->id]])
            ->assertRedirect();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, Requisition::count());
    }
}
