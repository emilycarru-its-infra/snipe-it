<?php

namespace Tests\Feature\Orders\Ui;

use App\Models\User;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    public function test_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.create'))
            ->assertOk();
    }

    public function test_order_can_be_created()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('orders.store'), [
                'order_number' => 'PO-CREATE-TEST',
                'status' => 'ordered',
            ])
            ->assertRedirect(route('orders.index'));

        $this->assertDatabaseHas('orders', ['order_number' => 'PO-CREATE-TEST', 'status' => 'ordered']);
    }

    public function test_order_number_is_required()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('orders.store'), ['status' => 'ordered'])
            ->assertSessionHasErrors('order_number');
    }
}
