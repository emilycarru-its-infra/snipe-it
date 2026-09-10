<?php

namespace Tests\Feature\Orders\Api;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

/**
 * The Orders API is otherwise index/show, so a line written by the vendor
 * webhook could only be removed from a browser. A bad unattended ingest needs
 * an unattended way back out.
 */
class DeleteAnOrderItemApiTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_a_line_item_can_be_deleted_through_the_api()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $item = OrderItem::factory()->create(['order_id' => $order->id]);

        $this->actingAsForApi($this->superuser())
            ->deleteJson(route('api.orders.items.destroy', [
                'order_id' => $order->id,
                'item_id' => $item->id,
            ]))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
    }

    public function test_a_line_belonging_to_another_order_is_refused()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $other = Order::factory()->create(['status' => 'ordered']);
        $item = OrderItem::factory()->create(['order_id' => $other->id]);

        $this->actingAsForApi($this->superuser())
            ->deleteJson(route('api.orders.items.destroy', [
                'order_id' => $order->id,
                'item_id' => $item->id,
            ]))
            ->assertNotFound()
            ->assertJson(['status' => 'error']);

        // The stale id must leave the other order's line untouched.
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'order_id' => $other->id]);
    }

    public function test_a_missing_line_is_refused_rather_than_erroring()
    {
        $order = Order::factory()->create(['status' => 'ordered']);

        $this->actingAsForApi($this->superuser())
            ->deleteJson(route('api.orders.items.destroy', [
                'order_id' => $order->id,
                'item_id' => 999999,
            ]))
            ->assertNotFound()
            ->assertJson(['status' => 'error']);
    }

    public function test_a_user_without_order_rights_cannot_delete_a_line()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $item = OrderItem::factory()->create(['order_id' => $order->id]);

        $this->actingAsForApi(User::factory()->create())
            ->deleteJson(route('api.orders.items.destroy', [
                'order_id' => $order->id,
                'item_id' => $item->id,
            ]))
            ->assertForbidden();

        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
    }
}
