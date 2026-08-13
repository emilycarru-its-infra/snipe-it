<?php

namespace Tests\Feature\Orders\Api;

use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
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

    public function test_ingest_stamps_fiscal_year_from_order_date()
    {
        $asset = Asset::factory()->create();

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => 'ORD-FY-STAMP',
                'order_date' => '2026-04-14', // ECU FY runs Apr–Mar → FY2026-27
                'items' => [['asset_id' => $asset->id, 'unit_cost' => 100]],
            ])
            ->assertOk();

        $this->assertEquals(
            'FY2026-27',
            Order::where('order_number', 'ORD-FY-STAMP')->value('fiscal_year')
        );
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

    public function test_a_second_invoice_for_the_same_asset_adds_a_line_rather_than_moving_it()
    {
        $asset = Asset::factory()->create();
        $actor = $this->actingAsForApi($this->superuser());

        // CDW bills the hardware, then the AppleCare for the same serials on
        // a separate invoice against the same order — PVXX158 carries
        // AJ7XC8E for 4 Mac minis and AJ7324Y for their AppleCare.
        $actor->postJson(route('api.orders.ingest'), [
            'order_number' => 'ORD-TWO-INV',
            'items' => [['asset_id' => $asset->id, 'unit_cost' => 4079.19, 'warranty_cost' => 0.85]],
            'invoice' => ['invoice_number' => 'EQUIP-INV', 'subtotal' => 4080.04, 'total' => 4284.04],
        ])->assertOk();

        $actor->postJson(route('api.orders.ingest'), [
            'order_number' => 'ORD-TWO-INV',
            'items' => [['asset_id' => $asset->id, 'unit_cost' => 0, 'warranty_cost' => 155.00]],
            'invoice' => ['invoice_number' => 'SOFT-INV', 'subtotal' => 155.00, 'total' => 162.75],
        ])->assertOk();

        $equipment = OrderInvoice::where('invoice_number', 'EQUIP-INV')->first();
        $soft = OrderInvoice::where('invoice_number', 'SOFT-INV')->first();

        // Each invoice keeps its own line — the second must not relocate the
        // first, which would leave EQUIP-INV with no line items at all and a
        // variance equal to its whole subtotal.
        $this->assertEquals(2, OrderItem::where('item_id', $asset->id)->where('item_type', Asset::class)->count());
        $this->assertEquals(4079.19, (float) OrderItem::where('invoice_id', $equipment->id)->value('unit_cost'));
        $this->assertEquals(155.00, (float) OrderItem::where('invoice_id', $soft->id)->value('warranty_cost'));
    }

    public function test_ingests_a_line_item_for_a_non_asset_stocked_item()
    {
        $component = Component::factory()->create();
        $consumable = Consumable::factory()->create();

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => 'ORD-TYPED',
                'items' => [
                    ['item_type' => 'component', 'item_id' => $component->id, 'unit_cost' => 408.39],
                    ['item_type' => 'consumable', 'item_id' => $consumable->id, 'quantity' => 5, 'unit_cost' => 18.65],
                ],
                'invoice' => ['invoice_number' => 'TYPED-INV', 'subtotal' => 501.64, 'total' => 543.14],
            ])
            ->assertOk();

        $invoice = OrderInvoice::where('invoice_number', 'TYPED-INV')->first();
        $this->assertEquals(
            Component::class,
            OrderItem::where('item_id', $component->id)->where('item_type', Component::class)->value('item_type')
        );

        // The whole point: the invoice reconciles. 408.39 + 5 x 18.65 = 501.64.
        $this->assertEqualsWithDelta(501.64, $invoice->fresh()->expectedSubtotal(), 0.001);
        $this->assertEqualsWithDelta(0.0, $invoice->fresh()->variance(), 0.001);
    }

    public function test_ingests_an_unlinked_line_carrying_only_a_description()
    {
        // CDW invoices installation labour and recycling fees that are not a
        // record of anything — they still have to land or the invoice reads
        // as an unexplained variance.
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => 'ORD-UNLINKED',
                'items' => [
                    ['description' => 'INSTALLATION LABOUR', 'unit_cost' => 8.73],
                    ['description' => 'BC PRINTER RECYCLING FEE', 'quantity' => 2, 'unit_cost' => 6.95],
                ],
                'invoice' => ['invoice_number' => 'UNLINKED-INV', 'subtotal' => 22.63, 'total' => 25.35],
            ])
            ->assertOk();

        $order = Order::where('order_number', 'ORD-UNLINKED')->first();

        // Two distinct lines, not one collapsed row — the description is what
        // separates them, since neither has a record to key on.
        $this->assertEquals(2, $order->items()->count());
        $this->assertEqualsWithDelta(
            0.0,
            OrderInvoice::where('invoice_number', 'UNLINKED-INV')->first()->variance(),
            0.001
        );

        // An invoiced non-asset line is received; nothing else can say so.
        $this->assertEquals(2, $order->items()->whereNotNull('received_at')->count());
        $this->assertEquals('received', $order->fresh()->status);
    }

    public function test_an_unlinked_line_is_idempotent_on_its_description()
    {
        $payload = [
            'order_number' => 'ORD-UNLINKED-IDEM',
            'items' => [['description' => 'CAT6 PATCH CABLE 3FT', 'quantity' => 5, 'unit_cost' => 11.55]],
            'invoice' => ['invoice_number' => 'UNLINKED-IDEM', 'subtotal' => 57.75, 'total' => 64.68],
        ];

        $actor = $this->actingAsForApi($this->superuser());
        $actor->postJson(route('api.orders.ingest'), $payload)->assertOk();
        $actor->postJson(route('api.orders.ingest'), $payload)->assertOk();

        $this->assertEquals(1, Order::where('order_number', 'ORD-UNLINKED-IDEM')->first()->items()->count());
    }

    public function test_rejects_a_line_that_names_neither_an_item_nor_a_description()
    {
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => 'ORD-ANON',
                'items' => [['unit_cost' => 99.99]],
            ])
            ->assertStatusMessageIs('error');

        $this->assertDatabaseMissing('orders', ['order_number' => 'ORD-ANON']);
    }

    public function test_rejects_a_line_pointing_at_an_item_that_does_not_exist()
    {
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => 'ORD-GHOST',
                'items' => [['item_type' => 'component', 'item_id' => 999999, 'unit_cost' => 10]],
            ])
            ->assertStatusMessageIs('error');

        $this->assertDatabaseMissing('orders', ['order_number' => 'ORD-GHOST']);
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
