<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderShipment;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderShipmentFactory extends Factory
{
    protected $model = OrderShipment::class;

    public function definition()
    {
        return [
            'order_id' => Order::factory(),
            'tracking_number' => strtoupper($this->faker->bothify('1Z######??')),
            'tracking_carrier' => $this->faker->randomElement(['UPS', 'FedEx', 'Purolator', 'Canada Post']),
        ];
    }
}
