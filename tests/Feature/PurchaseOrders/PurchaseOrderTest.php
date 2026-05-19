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

    public function test_invoiced_total_counts_only_invoiced_line_items()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);
        $order = Order::factory()->create(['status' => 'ordered', 'purchase_order_id' => $po->id]);
        $invoice = OrderInvoice::factory()->create(['order_id' => $order->id]);

        OrderItem::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id,
            'invoice_id' => $invoice->id, 'quantity' => 1, 'unit_cost' => 4000, 'warranty_cost' => 0,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id, 'purchase_order_id' => $po->id,
            'quantity' => 1, 'unit_cost' => 2000, 'warranty_cost' => 0,
        ]);

        // Both items count as committed; only the invoiced one counts as invoiced.
        $this->assertEquals(6000.0, $po->committedTotal());
        $this->assertEquals(4000.0, $po->invoicedTotal());
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
}
