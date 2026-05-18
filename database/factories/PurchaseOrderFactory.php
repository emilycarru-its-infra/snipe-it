<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition()
    {
        return [
            'po_number' => 'P00'.$this->faker->unique()->numberBetween(10000, 99999),
            'title' => $this->faker->words(3, true),
            'fiscal_year' => 'FY2025-26',
            'budget' => $this->faker->randomFloat(2, 10000, 500000),
            'status' => 'open',
            'order_date' => $this->faker->date(),
        ];
    }
}
