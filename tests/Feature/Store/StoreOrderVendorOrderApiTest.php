<?php

namespace Tests\Feature\Store;

use App\Mail\StoreVendorOrderMail;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The vendor's "order #X submitted", recorded against the store orders it
 * answers: a vendor order under their purchase order, which the shipment
 * webhook and the invoices then arrive under.
 */
class StoreOrderVendorOrderApiTest extends TestCase
{
    private const NUMBER = 'VO-1001';

    private function sentOrder(array $attributes = []): StoreOrder
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Example Reseller'], ['order_emails' => 'rep@example.com']);

        $item = CatalogItem::create([
            'name' => 'Laptop | 14" | 16GB | 1TB',
            'family' => 'Laptop',
            'category' => 'Laptops',
            'product_type' => 'standard',
            'vendor_sku' => 'SKU-1',
            'mfr_part_number' => 'PART-1',
            'unit_cost' => 2500.00,
            'price_type' => 'quoted',
            'model_id' => AssetModel::factory()->create()->getKey(),
            'supplier_id' => $supplier->id,
            'show_in_store' => 1,
        ]);

        $order = StoreOrder::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'status' => 'ordered',
            'program' => 'faculty',
            'funding_account' => 'lease_admin',
            'lease_schedule' => '100000-009',
            'vendor_sent_at' => now()->subWeek(),
            'quote_number' => 'Q-100',
            'quote_total' => 2900.00,
        ], $attributes));

        StoreOrderItem::create([
            'store_order_id' => $order->id,
            'catalog_item_id' => $item->id,
            'description' => $item->name,
            'quantity' => 1,
            'unit_cost' => 2500.00,
        ]);

        return $order->load('items');
    }

    private function placeholderFor(StoreOrder $order): Asset
    {
        return Asset::factory()->create(['order_number' => $order->reference(), 'serial' => null]);
    }

    private function record(StoreOrder $order, string $number = self::NUMBER)
    {
        return $this->postJson(route('api.store-orders.vendor-order'), [
            'orders' => [$order->id],
            'vendor_order_number' => $number,
        ]);
    }

    public function test_the_vendor_number_raises_an_order_under_the_purchase_order()
    {
        $purchaseOrder = PurchaseOrder::factory()->create(['po_number' => 'P0099200']);
        $order = $this->sentOrder(['purchase_order_id' => $purchaseOrder->id]);
        $other = $this->sentOrder(['purchase_order_id' => $purchaseOrder->id]);
        $device = $this->placeholderFor($order);

        Passport::actingAs(User::factory()->superuser()->create());

        $this->record($order, strtolower(self::NUMBER))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.order', self::NUMBER)
            ->assertJsonPath('payload.purchase_order', 'P0099200')
            ->assertJsonPath('payload.created', true);

        $vendorOrder = Order::where('order_number', self::NUMBER)->firstOrFail();
        $this->assertSame($purchaseOrder->id, $vendorOrder->purchase_order_id);
        $this->assertSame(self::NUMBER, $vendorOrder->vendor_order_number);
        $this->assertSame('100000-009', $vendorOrder->lease_schedule);
        $this->assertSame('Q-100', $vendorOrder->quote_number);
        $this->assertNotNull($vendorOrder->quote_confirmed_at);
        $this->assertSame(1, $vendorOrder->items()->count());
        $this->assertSame('SKU-1', $vendorOrder->items()->first()->vendor_sku);

        $this->assertNotNull($order->fresh()->confirmed_at);

        // A shared purchase order: the device keeps the store reference
        // that ties it to its requester, and no second set is raised.
        $this->assertSame($order->reference(), $device->fresh()->order_number);
        $this->assertSame(1, Asset::where('order_number', $order->reference())->count());
        $this->assertNull($other->fresh()->confirmed_at);
    }

    public function test_recording_the_same_number_again_changes_nothing()
    {
        $purchaseOrder = PurchaseOrder::factory()->create();
        $order = $this->sentOrder(['purchase_order_id' => $purchaseOrder->id]);

        Passport::actingAs(User::factory()->superuser()->create());

        $this->record($order)->assertOk();
        $this->record($order)
            ->assertOk()
            ->assertJsonPath('payload.created', false);

        $this->assertSame(1, Order::where('order_number', self::NUMBER)->count());
    }

    public function test_an_unattached_lease_order_finds_its_schedules_purchase_order()
    {
        $purchaseOrder = PurchaseOrder::factory()->create(['po_number' => 'P0099300']);
        $this->sentOrder(['purchase_order_id' => $purchaseOrder->id]);
        $order = $this->sentOrder();

        Passport::actingAs(User::factory()->superuser()->create());

        $this->record($order)
            ->assertOk()
            ->assertJsonPath('payload.purchase_order', 'P0099300');

        $this->assertSame($purchaseOrder->id, $order->fresh()->purchase_order_id);
    }

    public function test_an_order_with_no_purchase_order_to_find_is_refused()
    {
        $order = $this->sentOrder(['lease_schedule' => null, 'funding_account' => 'purchase_admin']);

        Passport::actingAs(User::factory()->superuser()->create());

        $this->record($order)->assertStatus(422);

        $this->assertSame(0, Order::where('order_number', self::NUMBER)->count());
        $this->assertNull($order->fresh()->confirmed_at);
    }

    public function test_an_order_not_yet_sent_cannot_carry_a_vendor_number()
    {
        $purchaseOrder = PurchaseOrder::factory()->create();
        $order = $this->sentOrder(['purchase_order_id' => $purchaseOrder->id, 'status' => 'approved', 'vendor_sent_at' => null]);

        Passport::actingAs(User::factory()->superuser()->create());

        $this->record($order)->assertStatus(422);

        $this->assertSame(0, Order::where('order_number', self::NUMBER)->count());
    }

    public function test_a_dedicated_purchase_orders_devices_take_the_vendor_number()
    {
        $purchaseOrder = PurchaseOrder::factory()->create();
        $order = $this->sentOrder(['purchase_order_id' => $purchaseOrder->id]);
        $device = $this->placeholderFor($order);

        Passport::actingAs(User::factory()->superuser()->create());

        $this->record($order)->assertOk();

        $this->assertSame(self::NUMBER, $device->fresh()->order_number);
    }

    public function test_the_vendor_number_is_not_open_to_everyone()
    {
        $order = $this->sentOrder();

        Passport::actingAs(User::factory()->create());

        $this->record($order)->assertForbidden();
    }

    public function test_a_quote_request_with_no_purchase_order_names_its_store_reference()
    {
        $order = $this->sentOrder();

        $subject = (new StoreVendorOrderMail(collect([$order])))->envelope()->subject;

        $this->assertStringContainsString($order->reference(), $subject);
        $this->assertStringContainsString('purchase order to follow', $subject);
    }

    public function test_a_long_quote_request_keeps_its_subject_short()
    {
        $orders = collect(range(1, 4))->map(fn () => $this->sentOrder());

        $subject = (new StoreVendorOrderMail($orders))->envelope()->subject;

        $this->assertStringNotContainsString($orders->first()->reference(), $subject);
    }
}
