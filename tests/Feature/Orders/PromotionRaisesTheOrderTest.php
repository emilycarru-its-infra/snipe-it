<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\RequisitionPromotion;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * REQM to purchase order to order — and the order is what provisions.
 *
 * The chain used to stop at the purchase order. Forty-two laptops on a
 * placed order existed nowhere but as text on a requisition: no order row,
 * so nothing committed; no assets, so the shipment webhook had nothing to
 * claim and a deployment wave built for them came up empty.
 */
class PromotionRaisesTheOrderTest extends TestCase
{
    private function requisitionWithLines(): Requisition
    {
        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep@cdw.ca']);

        $laptop = CatalogItem::create([
            'name' => 'MacBook Air | 13" | M5', 'family' => 'MacBook Air', 'category' => 'Laptops',
            'product_type' => 'standard', 'vendor_sku' => '9094662', 'unit_cost' => 2150.48,
            'price_type' => 'quoted', 'supplier_id' => $supplier->id,
            'model_id' => AssetModel::factory()->create()->getKey(),
        ]);

        // Warranty is money on the order, not a device — it resolves to no
        // model and must not become an asset.
        $care = CatalogItem::create([
            'name' => 'AppleCare+ for Schools', 'family' => 'AppleCare', 'category' => 'Warranty',
            'product_type' => 'warranty', 'vendor_sku' => '8154132', 'unit_cost' => 239.20,
            'price_type' => 'quoted', 'supplier_id' => $supplier->id, 'model_id' => null,
        ]);

        $requisition = Requisition::create([
            'title' => 'Foundation Mobile MacBook Labs',
            'status' => 'requisitioned',
            'requisition_number' => '0017859',
            'supplier_id' => $supplier->id,
            'created_by' => User::factory()->superuser()->create()->id,
        ]);

        foreach ([[$laptop, 42, 2150.48], [$care, 42, 239.20]] as [$item, $qty, $cost]) {
            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'catalog_item_id' => $item->id,
                'description' => $item->name,
                'quantity' => $qty,
                'unit_cost' => $cost,
            ]);
        }

        return $requisition->load('items.catalogItem');
    }

    public function test_promotion_raises_an_order_and_provisions_its_devices()
    {
        $requisition = $this->requisitionWithLines();

        $this->actingAs(User::factory()->superuser()->create());

        $purchaseOrder = app(RequisitionPromotion::class)->promote($requisition, [
            'po_number' => 'P0026022',
            'fiscal_year' => 'FY2026-27',
            'budget' => 112771.26,
            'order_date' => '2026-08-07',
        ], UploadedFile::fake()->create('po.pdf', 10, 'application/pdf'));

        $order = Order::where('purchase_order_id', $purchaseOrder->id)->first();
        $this->assertNotNull($order, 'promotion should raise an order under the purchase order');
        $this->assertSame('P0026022', $order->order_number);
        $this->assertSame(2, $order->items->count());

        // Forty-two laptops exist; the warranty line does not become a device.
        $assets = Asset::where('order_number', 'P0026022')->get();
        $this->assertCount(42, $assets);
        $this->assertSame('P0026022', $assets->first()->po_number);
        $this->assertEqualsWithDelta(2150.48, (float) $assets->first()->purchase_cost, 0.01);
    }

    public function test_promotion_does_not_raise_a_second_order_for_the_same_purchase_order()
    {
        $requisition = $this->requisitionWithLines();

        $this->actingAs(User::factory()->superuser()->create());

        $purchaseOrder = app(RequisitionPromotion::class)->promote($requisition, [
            'po_number' => 'P0026022',
            'fiscal_year' => 'FY2026-27',
        ], UploadedFile::fake()->create('po.pdf', 10, 'application/pdf'));

        // A second requisition promoted onto the SAME purchase order must
        // not double the fleet — promotion can be retried after a failure.
        $second = $this->requisitionWithLines();
        app(RequisitionPromotion::class)->promote($second, [
            'purchase_order_id' => $purchaseOrder->id,
        ], null);

        $this->assertSame(1, Order::where('purchase_order_id', $purchaseOrder->id)->count());
        $this->assertSame(42, Asset::where('order_number', 'P0026022')->count());
    }
}
