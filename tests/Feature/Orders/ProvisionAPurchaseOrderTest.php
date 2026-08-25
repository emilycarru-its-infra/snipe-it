<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Backfilling a purchase order raised before promotion provisioned
 * anything — over the API, because the app runs in a container with no
 * shell to run artisan in.
 */
class ProvisionAPurchaseOrderTest extends TestCase
{
    private function purchaseOrderWithRequisition(): PurchaseOrder
    {
        $supplier = Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => 'rep@cdw.ca']);

        $laptop = CatalogItem::create([
            'name' => 'MacBook Air | 13" | M5', 'family' => 'MacBook Air', 'category' => 'Laptops',
            'product_type' => 'standard', 'vendor_sku' => '9094662', 'unit_cost' => 2150.48,
            'price_type' => 'quoted', 'supplier_id' => $supplier->id,
            'model_id' => AssetModel::factory()->create()->getKey(),
        ]);

        $care = CatalogItem::create([
            'name' => 'AppleCare+ for Schools', 'family' => 'AppleCare', 'category' => 'Warranty',
            'product_type' => 'warranty', 'vendor_sku' => '8154132', 'unit_cost' => 239.20,
            'price_type' => 'quoted', 'supplier_id' => $supplier->id, 'model_id' => null,
        ]);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'po_number' => 'P0026022', 'fiscal_year' => 'FY2026-27', 'budget' => 112771.26,
            'order_date' => '2026-08-07',
        ]);

        $requisition = Requisition::create([
            'title' => 'Foundation Mobile MacBook Labs',
            'status' => 'ordered',
            'requisition_number' => '0017859',
            'supplier_id' => $supplier->id,
            'created_by' => User::factory()->superuser()->create()->id,
        ]);
        $requisition->purchase_order_id = $purchaseOrder->id;
        $requisition->save();

        foreach ([[$laptop, 42, 2150.48], [$care, 42, 239.20]] as [$item, $qty, $cost]) {
            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'catalog_item_id' => $item->id,
                'description' => $item->name,
                'quantity' => $qty,
                'unit_cost' => $cost,
            ]);
        }

        return $purchaseOrder;
    }

    public function test_a_dry_run_reports_the_shortfall_and_writes_nothing()
    {
        $purchaseOrder = $this->purchaseOrderWithRequisition();

        Passport::actingAs(User::factory()->superuser()->create());

        $this->postJson(route('api.purchase-orders.provision', $purchaseOrder->id), ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('payload.device_units_on_requisition', 42)
            ->assertJsonPath('payload.would_create', 42)
            ->assertJsonPath('payload.order_existed', false);

        $this->assertSame(0, Order::where('purchase_order_id', $purchaseOrder->id)->count());
        $this->assertSame(0, Asset::where('order_number', 'P0026022')->count());
    }

    public function test_it_raises_the_order_and_provisions_the_devices()
    {
        $purchaseOrder = $this->purchaseOrderWithRequisition();

        Passport::actingAs(User::factory()->superuser()->create());

        $this->postJson(route('api.purchase-orders.provision', $purchaseOrder->id))
            ->assertOk()
            ->assertJsonPath('payload.assets_created', 42);

        $this->assertSame(1, Order::where('purchase_order_id', $purchaseOrder->id)->count());
        // The warranty line is money, not a device.
        $this->assertSame(42, Asset::where('order_number', 'P0026022')->count());
    }

    public function test_running_it_twice_tops_up_rather_than_doubling()
    {
        $purchaseOrder = $this->purchaseOrderWithRequisition();

        Passport::actingAs(User::factory()->superuser()->create());

        $this->postJson(route('api.purchase-orders.provision', $purchaseOrder->id))->assertOk();

        // A retry after a partial run must not buy the fleet twice.
        $this->postJson(route('api.purchase-orders.provision', $purchaseOrder->id))
            ->assertOk()
            ->assertJsonPath('payload.assets_now', 42);

        $this->assertSame(1, Order::where('purchase_order_id', $purchaseOrder->id)->count());
        $this->assertSame(42, Asset::where('order_number', 'P0026022')->count());
    }
}
