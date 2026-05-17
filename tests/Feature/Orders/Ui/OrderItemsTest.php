<?php

namespace Tests\Feature\Orders\Ui;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

class OrderItemsTest extends TestCase
{
    public function test_can_attach_an_asset_to_an_order()
    {
        $order = Order::factory()->create();
        $asset = Asset::factory()->create();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('orders.items.store', $order->id), [
                'item_type' => 'asset',
                'item_id_asset' => $asset->id,
                'quantity' => 2,
            ])
            ->assertRedirect(route('orders.show', $order->id));

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $asset->id,
            'quantity' => 2,
        ]);
    }

    public function test_can_remove_a_line_item()
    {
        $order = Order::factory()->create();
        $asset = Asset::factory()->create();
        $item = OrderItem::create([
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $asset->id,
            'quantity' => 1,
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->delete(route('orders.items.destroy', ['order' => $order->id, 'item' => $item->id]))
            ->assertRedirect(route('orders.show', $order->id));

        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
    }

    public function test_order_view_renders_with_line_items()
    {
        $order = Order::factory()->create();
        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => Asset::factory()->create()->id,
            'quantity' => 1,
            'unit_cost' => 99.99,
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.show', $order->id))
            ->assertOk();
    }
}
