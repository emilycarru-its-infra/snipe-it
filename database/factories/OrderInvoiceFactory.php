<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderInvoiceFactory extends Factory
{
    protected $model = OrderInvoice::class;

    public function definition()
    {
        $subtotal = $this->faker->randomFloat(2, 100, 10000);
        $gst = round($subtotal * 0.05, 2);
        $pst = round($subtotal * 0.07, 2);

        return [
            'order_id' => Order::factory(),
            'invoice_number' => strtoupper($this->faker->unique()->bothify('INV-#####')),
            'invoice_date' => $this->faker->date(),
            'subtotal' => $subtotal,
            'tax_gst' => $gst,
            'tax_pst' => $pst,
            'total' => $subtotal + $gst + $pst,
        ];
    }
}
