<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition()
    {
        return [
            'order_number' => 'PO-'.$this->faker->unique()->numberBetween(10000, 999999),
            'status' => $this->faker->randomElement(Order::STATUSES),
            'order_date' => $this->faker->date(),
            'order_cost' => $this->faker->randomFloat(2, 50, 5000),
        ];
    }
}
