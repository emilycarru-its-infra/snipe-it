<?php

namespace Tests\Feature\Store;

use App\Mail\StoreVendorOrderMail;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\VendorOrderCsv;
use Tests\TestCase;

/**
 * What the vendor is asked to supply.
 *
 * A batch of store orders is our paperwork — sixteen references, sixteen
 * requesters, one laptop each. The desk that keys it wants a parts list, so
 * the same model across many orders is one line with the quantity summed,
 * under the purchase order, account and lease schedule it is placed against.
 */
class VendorOrderBundlingTest extends TestCase
{
    private function shelfItem(string $name, string $sku, float $cost): CatalogItem
    {
        return CatalogItem::create([
            'name' => $name,
            'family' => $name,
            'category' => 'Laptops',
            'product_type' => 'standard',
            'vendor_sku' => $sku,
            'mfr_part_number' => 'MFR-'.$sku,
            'unit_cost' => $cost,
            'price_type' => 'quoted',
            'show_in_store' => true,
            'supplier_id' => Supplier::firstOrCreate(['name' => 'CDW Canada Inc'], ['order_emails' => 'rep@cdw.ca'])->id,
            'model_id' => AssetModel::factory()->create()->getKey(),
        ]);
    }

    private function orderFor(CatalogItem $item, PurchaseOrder $po): StoreOrder
    {
        $order = StoreOrder::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'approved',
            'program' => 'faculty',
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-009',
        ]);
        $order->purchase_order_id = $po->id;
        $order->save();

        StoreOrderItem::create([
            'store_order_id' => $order->id,
            'catalog_item_id' => $item->id,
            'description' => $item->name,
            'vendor_sku' => $item->vendor_sku,
            'mfr_part_number' => $item->mfr_part_number,
            'quantity' => 1,
            'unit_cost' => $item->unit_cost,
        ]);

        return $order;
    }

    public function test_the_same_model_across_many_orders_is_one_line()
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'P0026041', 'fiscal_year' => 'FY2026-27']);
        $air = $this->shelfItem('MacBook Air | 13" | M5 | 16GB | 1TB | Silver', '9094662', 2100.00);
        $pro = $this->shelfItem('MacBook Pro | 14" | M5 Pro | 24GB | 2TB | Black', '8544413', 4000.00);

        foreach (range(1, 13) as $ignored) {
            $this->orderFor($air, $po);
        }
        $this->orderFor($pro, $po);

        $orders = StoreOrder::with('items.catalogItem', 'user')->get();
        $body = (new StoreVendorOrderMail($orders))->render();

        // Thirteen laptops, one line saying thirteen.
        $this->assertStringContainsString('MacBook Air | 13" | M5 | 16GB | 1TB | Silver', $body);
        // Once, not thirteen times — the markdown mailer decodes entities,
        // so the rendered body carries the raw quote.
        $this->assertSame(1, substr_count($body, 'MacBook Air | 13" | M5 | 16GB | 1TB | Silver'));

        // The facts the desk needs, once.
        $this->assertStringContainsString('P0026041', $body);
        $this->assertStringContainsString('301452-009', $body);

        // Our paperwork stays on our side.
        $this->assertStringNotContainsString('ECU-STORE-', $body);
    }

    public function test_the_csv_carries_one_row_per_part()
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'P0026041', 'fiscal_year' => 'FY2026-27']);
        $air = $this->shelfItem('MacBook Air | 13" | M5 | 16GB | 1TB | Silver', '9094662', 2100.00);
        $pro = $this->shelfItem('MacBook Pro | 14" | M5 Pro | 24GB | 2TB | Black', '8544413', 4000.00);

        foreach (range(1, 13) as $ignored) {
            $this->orderFor($air, $po);
        }
        $this->orderFor($pro, $po);
        $this->orderFor($pro, $po);

        $csv = (new VendorOrderCsv(StoreOrder::with('items.catalogItem')->get()))->contents();
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));

        // Header plus two parts, not header plus fifteen orders.
        $this->assertCount(3, $lines);

        $quantities = array_map(fn ($line) => (int) str_getcsv($line)[3], array_slice($lines, 1));
        sort($quantities);
        $this->assertSame([2, 13], $quantities);
    }
}
