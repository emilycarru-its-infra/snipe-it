<?php

namespace Tests\Feature\Orders\Ui;

use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class BulkDeleteOrdersTest extends TestCase
{
    public function test_orders_can_be_bulk_deleted()
    {
        $orders = Order::factory()->count(3)->create();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('orders.bulk.delete'), ['ids' => $orders->pluck('id')->toArray()])
            ->assertRedirect(route('orders.index'));

        foreach ($orders as $order) {
            $this->assertSoftDeleted($order);
        }
    }
}
