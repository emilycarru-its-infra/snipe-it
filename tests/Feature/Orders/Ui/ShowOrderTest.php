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
}
