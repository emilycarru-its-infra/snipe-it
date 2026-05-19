<?php

namespace Tests\Feature\Orders\Api;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

class IngestOrderTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_requires_permission()
    {
        $asset = Asset::factory()->create();

        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => 'ORD-NOAUTH',
                'items' => [['asset_id' => $asset->id]],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('orders', ['order_number' => 'ORD-NOAUTH']);
    }

    public function test_ingests_an_order_with_line_items_linked_to_assets()
    {
        $asset = Asset::factory()->create();

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => 'ORD-INGEST-1',
                'order_date' => '2025-07-22',
                'items' => [
                    ['asset_id' => $asset->id, 'description' => 'Test Laptop', 'quantity' => 1, 'unit_cost' => 1499.00],
                ],
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $order = Order::where('order_number', 'ORD-INGEST-1')->first();
        $this->assertNotNull($order);
        $this->assertFalse((bool) $order->is_planned);

        $item = $order->items()->first();
        $this->assertEquals(Asset::class, $item->item_type);
        $this->assertEquals($asset->id, $item->item_id);
        $this->assertEquals(1499.00, (float) $item->unit_cost);
    }

    public function test_ingest_is_idempotent()
    {
        $asset = Asset::factory()->create();
        $payload = [
            'order_number' => 'ORD-IDEM',
            'items' => [['asset_id' => $asset->id, 'unit_cost' => 100]],
            'invoice' => ['invoice_number' => 'INV-IDEM', 'subtotal' => 100, 'total' => 112],
        ];

        $actor = $this->actingAsForApi($this->superuser());
        $actor->postJson(route('api.orders.ingest'), $payload)->assertOk();
        $actor->postJson(route('api.orders.ingest'), $payload)->assertOk();

        // A re-pushed webhook fills gaps rather than duplicating records.
        $this->assertEquals(1, Order::where('order_number', 'ORD-IDEM')->count());
        $this->assertEquals(1, OrderInvoice::where('invoice_number', 'INV-IDEM')->count());
        $this->assertEquals(1, OrderItem::where('item_id', $asset->id)->where('item_type', Asset::class)->count());
    }

    public function test_records_the_invoice_against_the_order()
    {
        $asset = Asset::factory()->create();

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => 'ORD-INV',
                'items' => [['asset_id' => $asset->id]],
                'invoice' => [
                    'invoice_number' => 'CDWINV-77',
                    'invoice_date' => '2025-08-01',
                    'subtotal' => 2000,
                    'tax_gst' => 100,
                    'tax_pst' => 140,
                    'shipping' => 0,
                    'total' => 2240,
                ],
            ])
            ->assertOk();

        $invoice = OrderInvoice::where('invoice_number', 'CDWINV-77')->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(2240.0, (float) $invoice->total);
        // The line item is linked to the invoice it was billed on.
        $this->assertEquals($invoice->id, OrderItem::where('item_id', $asset->id)->first()->invoice_id);
    }

    public function test_creates_one_shipment_per_distinct_tracking_number()
    {
        $assetA = Asset::factory()->create();
        $assetB = Asset::factory()->create();
        $assetC = Asset::factory()->create();

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => 'ORD-SHIP',
                'items' => [
                    ['asset_id' => $assetA->id, 'tracking_number' => 'TRACK-1', 'tracking_carrier' => 'UPS'],
                    ['asset_id' => $assetB->id, 'tracking_number' => 'TRACK-1', 'tracking_carrier' => 'UPS'],
                    ['asset_id' => $assetC->id, 'tracking_number' => 'TRACK-2', 'tracking_carrier' => 'FedEx'],
                ],
            ])
            ->assertOk();

        $order = Order::where('order_number', 'ORD-SHIP')->first();
        // Two items share TRACK-1, so the order has two shipments, not three.
        $this->assertEquals(2, $order->shipments()->count());

        $shipment1 = OrderShipment::where('tracking_number', 'TRACK-1')->first();
        $itemA = OrderItem::where('item_id', $assetA->id)->first();
        $itemB = OrderItem::where('item_id', $assetB->id)->first();
        $this->assertEquals($shipment1->id, $itemA->shipment_id);
        $this->assertEquals($shipment1->id, $itemB->shipment_id);
    }

    public function test_links_to_an_existing_purchase_order_only()
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'P0099999']);
        $assetMatched = Asset::factory()->create();
        $assetUnknown = Asset::factory()->create();

        $actor = $this->actingAsForApi($this->superuser());

        $actor->postJson(route('api.orders.ingest'), [
            'order_number' => 'ORD-PO-MATCH',
            'purchase_order_number' => 'P0099999',
            'items' => [['asset_id' => $assetMatched->id]],
        ])->assertOk();

        $actor->postJson(route('api.orders.ingest'), [
            'order_number' => 'ORD-PO-UNKNOWN',
            'purchase_order_number' => 'P0000000',
            'items' => [['asset_id' => $assetUnknown->id]],
        ])->assertOk();

        $this->assertEquals($po->id, Order::where('order_number', 'ORD-PO-MATCH')->first()->purchase_order_id);
        // An unknown PO number leaves the link null — finance owns PO creation.
        $this->assertNull(Order::where('order_number', 'ORD-PO-UNKNOWN')->first()->purchase_order_id);
        $this->assertEquals($po->id, OrderItem::where('item_id', $assetMatched->id)->first()->purchase_order_id);
    }
}
