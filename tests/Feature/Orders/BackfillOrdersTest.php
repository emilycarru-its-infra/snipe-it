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

    public function test_since_option_overrides_the_month_window()
    {
        Asset::factory()->create([
            'order_number' => 'PO-SINCE-IN',
            'purchase_date' => now()->subMonths(18)->format('Y-m-d'),
            'purchase_cost' => 100,
        ]);
        Asset::factory()->create([
            'order_number' => 'PO-SINCE-OUT',
            'purchase_date' => now()->subMonths(30)->format('Y-m-d'),
            'purchase_cost' => 100,
        ]);

        $this->artisan('orders:backfill', ['--since' => now()->subMonths(24)->format('Y-m-d')])
            ->assertExitCode(0);

        // The 18-month-old asset is outside the default 12-month window but
        // inside the explicit --since cutoff; the 30-month-old one is not.
        $this->assertDatabaseHas('orders', ['order_number' => 'PO-SINCE-IN']);
        $this->assertDatabaseMissing('orders', ['order_number' => 'PO-SINCE-OUT']);
    }

    public function test_stamps_fiscal_year_from_the_order_date()
    {
        Asset::factory()->create([
            'order_number' => 'PO-FY-A',
            'purchase_date' => '2025-06-15',
            'purchase_cost' => 100,
        ]);
        Asset::factory()->create([
            'order_number' => 'PO-FY-B',
            'purchase_date' => '2026-02-10',
            'purchase_cost' => 100,
        ]);

        $this->artisan('orders:backfill', ['--since' => '2025-04-01'])->assertExitCode(0);

        // ECU's fiscal year runs April-March, so both June 2025 and
        // February 2026 fall in FY2025-26.
        $this->assertEquals('FY2025-26', Order::where('order_number', 'PO-FY-A')->value('fiscal_year'));
        $this->assertEquals('FY2025-26', Order::where('order_number', 'PO-FY-B')->value('fiscal_year'));
    }
}
