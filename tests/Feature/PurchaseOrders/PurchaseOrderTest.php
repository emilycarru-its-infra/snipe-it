<?php

namespace Tests\Feature\PurchaseOrders;

use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_index_page_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('purchase-orders.index'))
            ->assertOk();
    }

    public function test_view_page_renders()
    {
        $po = PurchaseOrder::factory()->create();

        $this->actingAs($this->superuser())
            ->get(route('purchase-orders.show', ['purchase_order' => $po->id]))
            ->assertOk();
    }

    public function test_a_purchase_order_can_be_created()
    {
        $this->actingAs($this->superuser())
            ->post(route('purchase-orders.store'), [
                'po_number' => 'P0099999',
                'status' => 'open',
                'fiscal_year' => 'FY2025-26',
                'budget' => 50000,
            ])
            ->assertRedirect(route('purchase-orders.index'));

        $this->assertDatabaseHas('purchase_orders', [
            'po_number' => 'P0099999',
            'fiscal_year' => 'FY2025-26',
        ]);
    }

    public function test_a_purchase_order_can_be_updated()
    {
        $po = PurchaseOrder::factory()->create(['status' => 'open']);

        $this->actingAs($this->superuser())
            ->put(route('purchase-orders.update', ['purchase_order' => $po->id]), [
                'po_number' => $po->po_number,
                'status' => 'amended',
                'budget' => 75000,
            ])
            ->assertRedirect(route('purchase-orders.index'));

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $po->id,
            'status' => 'amended',
        ]);
    }

    public function test_a_purchase_order_can_be_deleted()
    {
        $po = PurchaseOrder::factory()->create();

        $this->actingAs($this->superuser())
            ->delete(route('purchase-orders.destroy', ['purchase_order' => $po->id]))
            ->assertRedirect(route('purchase-orders.index'));

        $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
    }

    public function test_an_order_can_be_linked_to_a_purchase_order()
    {
        $po = PurchaseOrder::factory()->create();
        $order = Order::factory()->create(['status' => 'ordered']);

        $this->actingAs($this->superuser())
            ->put(route('orders.update', $order->id), [
                'order_number' => $order->order_number,
                'purchase_order_id' => $po->id,
            ])
            ->assertRedirect(route('orders.index'));

        $this->assertEquals($po->id, $order->fresh()->purchase_order_id);
    }

    public function test_committed_total_sums_line_items_charged_to_the_po()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'purchase_order_id' => $po->id,
            'quantity' => 2,
            'unit_cost' => 1000,
            'warranty_cost' => 250,
        ]);

        // Two units at 1000 plus 250 warranty.
        $this->assertEquals(2250.0, $po->committedTotal());
        $this->assertEquals(7750.0, $po->remaining());
        $this->assertFalse($po->isOverBudget());
    }

    public function test_invoiced_total_sums_invoice_subtotals_charged_to_the_po()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);

        OrderInvoice::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $po->id, 'subtotal' => 4000]);
        OrderInvoice::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $po->id, 'subtotal' => 2000]);
        // An invoice not charged to this PO is excluded.
        OrderInvoice::factory()->create(['order_id' => $order->id, 'subtotal' => 9999]);

        $this->assertEquals(6000.0, $po->invoicedTotal());
    }

    public function test_a_purchase_order_over_budget_is_flagged()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id,
            'quantity' => 1, 'unit_cost' => 11000, 'warranty_cost' => 0,
        ]);

        $this->assertTrue($po->isOverBudget());
        $this->assertEquals(-1000.0, $po->remaining());
    }

    public function test_a_credit_hands_budget_room_back()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id,
            'quantity' => 1, 'unit_cost' => 9000, 'warranty_cost' => 0,
        ]);

        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'purchase_order_id' => $po->id,
            'invoice_type' => 'credit',
            'invoice_date' => '2025-10-01',
            'subtotal' => 2000,
        ]);

        // The credit is money back on the purchase order, so it buys room for
        // the next order rather than sitting only on the invoice. A credit
        // subtracts however its subtotal was keyed.
        $this->assertEquals(7000.0, $po->committedTotal());
        $this->assertEquals(3000.0, $po->remaining());
        $this->assertFalse($po->isOverBudget());
    }

    public function test_a_credit_keyed_negative_still_subtracts_once()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);

        OrderInvoice::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id,
            'invoice_type' => 'credit', 'invoice_date' => '2025-10-01', 'subtotal' => -2000,
        ]);

        $this->assertEquals(-2000.0, $po->committedTotal());
    }

    public function test_a_buyout_commits_against_the_po_without_a_line_item()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);

        // Nothing is delivered by a buyout — we already hold the equipment —
        // so there is no line item to carry the cost.
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'purchase_order_id' => $po->id,
            'invoice_type' => 'buyout',
            'invoice_date' => '2025-10-01',
            'subtotal' => 4000,
        ]);

        $this->assertEquals(4000.0, $po->committedTotal());
        $this->assertEquals(6000.0, $po->remaining());
    }

    public function test_a_regular_invoice_without_line_items_is_not_committed_twice()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);

        OrderItem::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id,
            'quantity' => 1, 'unit_cost' => 3000, 'warranty_cost' => 0,
        ]);
        // A regular invoice describes the same money the line items do.
        OrderInvoice::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id, 'subtotal' => 3000,
        ]);

        $this->assertEquals(3000.0, $po->committedTotal());
    }

    public function test_an_adjustment_lands_in_the_fiscal_year_of_its_invoice_date()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);

        OrderInvoice::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id,
            'invoice_type' => 'credit', 'invoice_date' => '2025-10-01', 'subtotal' => 2000,
        ]);
        OrderInvoice::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id,
            'invoice_type' => 'buyout', 'invoice_date' => '2026-10-01', 'subtotal' => 500,
        ]);

        $this->assertEquals(-2000.0, $po->committedTotalForFy('FY2025-26'));
        $this->assertEquals(500.0, $po->committedTotalForFy('FY2026-27'));
        $this->assertEquals(-1500.0, $po->committedTotalForFy(null));
    }

    public function test_a_dateless_adjustment_leaves_the_budget_alone()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);

        OrderInvoice::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id,
            'invoice_type' => 'buyout', 'invoice_date' => null, 'subtotal' => 4000,
        ]);

        $this->assertEquals(0.0, $po->committedTotal());
        $this->assertEquals(10000.0, $po->remaining());
    }
}
