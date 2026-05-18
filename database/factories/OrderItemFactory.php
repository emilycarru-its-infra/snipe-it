<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition()
    {
        return [
            'order_id' => Order::factory(),
            'description' => $this->faker->sentence(3),
            'quantity' => 1,
            'unit_cost' => $this->faker->randomFloat(2, 50, 5000),
        ];
    }
}
