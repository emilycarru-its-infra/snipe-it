<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Status defaults to a stable 'ordered'. A random status would sometimes
     * roll 'cancelled', which freezes recalculateStatus() and fails any test
     * asserting a derived status. Ask for another status explicitly.
     */
    public function definition()
    {
        return [
            'order_number' => 'PO-'.$this->faker->unique()->numberBetween(10000, 999999),
            'status' => 'ordered',
            'order_date' => $this->faker->date(),
            'order_cost' => $this->faker->randomFloat(2, 50, 5000),
        ];
    }

    public function cancelled()
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
