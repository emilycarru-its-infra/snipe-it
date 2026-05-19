<?php

namespace Tests\Feature\Orders\Ui;

use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class ShowOrderTest extends TestCase
{
    public function test_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.show', Order::factory()->create()->id))
            ->assertOk();
    }

    public function test_page_shows_collapsible_add_buttons_for_each_section()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.show', Order::factory()->create()->id))
            ->assertOk()
            ->assertSee('js-order-add-toggle')
            ->assertSee('js-order-add-cancel')
            ->assertSee('order-add-line-item')
            ->assertSee('order-add-shipment')
            ->assertSee('order-add-invoice');
    }
}
