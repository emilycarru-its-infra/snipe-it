<?php

namespace Tests\Feature\Orders\Ui;

use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class DeleteOrderTest extends TestCase
{
    public function test_order_can_be_deleted()
    {
        $order = Order::factory()->create();

        $this->actingAs(User::factory()->superuser()->create())
            ->delete(route('orders.destroy', $order->id))
            ->assertRedirect(route('orders.index'));

        $this->assertSoftDeleted($order);
    }
}
