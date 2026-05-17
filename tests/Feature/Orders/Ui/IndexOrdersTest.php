<?php

namespace Tests\Feature\Orders\Ui;

use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class IndexOrdersTest extends TestCase
{
    public function test_page_renders()
    {
        Order::factory()->count(3)->create();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.index'))
            ->assertOk();
    }
}
