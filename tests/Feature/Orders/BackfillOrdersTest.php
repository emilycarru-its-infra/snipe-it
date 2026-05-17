<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\Order;
use Tests\TestCase;

class BackfillOrdersTest extends TestCase
{
    public function test_creates_orders_and_line_items_from_assets()
    {
        $asset = Asset::factory()->create([
            'order_number' => 'PO-BACKFILL-1',
            'purchase_date' => now()->subMonths(2)->format('Y-m-d'),
            'purchase_cost' => 100,
        ]);

        $this->artisan('orders:backfill')->assertExitCode(0);

        $order = Order::where('order_number', 'PO-BACKFILL-1')->first();
        $this->assertNotNull($order);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $asset->id,
        ]);
    }

    public function test_is_idempotent()
    {
        Asset::factory()->create([
            'order_number' => 'PO-IDEM',
            'purchase_date' => now()->subMonth()->format('Y-m-d'),
            'purchase_cost' => 50,
        ]);

        $this->artisan('orders:backfill')->assertExitCode(0);
        $this->artisan('orders:backfill')->assertExitCode(0);

        $this->assertEquals(1, Order::where('order_number', 'PO-IDEM')->count());
    }

    public function test_skips_assets_outside_the_month_window()
    {
        Asset::factory()->create([
            'order_number' => 'PO-OLD',
            'purchase_date' => now()->subMonths(20)->format('Y-m-d'),
            'purchase_cost' => 100,
        ]);

        $this->artisan('orders:backfill', ['--months' => 12])->assertExitCode(0);

        $this->assertDatabaseMissing('orders', ['order_number' => 'PO-OLD']);
    }
}
