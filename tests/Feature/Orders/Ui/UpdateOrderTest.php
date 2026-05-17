<?php

namespace Tests\Feature\Orders\Ui;

use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class UpdateOrderTest extends TestCase
{
    public function test_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.edit', Order::factory()->create()->id))
            ->assertOk();
    }

    public function test_order_can_be_updated()
    {
        $order = Order::factory()->create(['status' => 'ordered']);

        $this->actingAs(User::factory()->superuser()->create())
            ->put(route('orders.update', $order->id), [
                'order_number' => 'PO-UPDATED',
                'status' => 'received',
            ])
            ->assertRedirect(route('orders.index'));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_number' => 'PO-UPDATED',
            'status' => 'received',
        ]);
    }
}
