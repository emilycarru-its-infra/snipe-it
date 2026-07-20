<?php

namespace Tests\Feature\Reports;

use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

class ProcurementPipelineTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_dashboard_renders_pipeline_rail_and_board()
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-PIPE-1', 'budget' => 10000]);
        Order::factory()->create([
            'order_number' => 'ORD-PIPE-OPEN',
            'status' => 'ordered',
            'is_planned' => false,
            'purchase_order_id' => $po->id,
        ]);
        Order::factory()->create([
            'order_number' => 'PLN-PIPE-1',
            'status' => 'ordered',
            'is_planned' => true,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.stage_budgeting'))
            ->assertSee(trans('admin/purchase-orders/general.stage_completed'))
            ->assertSee('ORD-PIPE-OPEN')
            ->assertSee('PLN-PIPE-1')
            ->assertSee(trans('admin/purchase-orders/general.pipeline_needs_po'))
            ->assertSee(trans('admin/purchase-orders/general.pipeline_returns_title'));
    }

    public function test_pending_invoice_appears_on_reconciling_column()
    {
        $order = Order::factory()->create(['order_number' => 'ORD-PIPE-INV', 'is_planned' => false]);
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-PIPE-1',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('INV-PIPE-1');
    }

    public function test_converting_planned_order_without_po_is_blocked()
    {
        $order = Order::factory()->create([
            'order_number' => 'PLN-GATE-1',
            'status' => 'ordered',
            'is_planned' => true,
        ]);

        $this->actingAs($this->superuser())
            ->from(route('orders.edit', $order))
            ->put(route('orders.update', $order), [
                'order_number' => 'PLN-GATE-1',
                'status' => 'ordered',
                'is_planned' => '0',
            ])
            ->assertRedirect(route('orders.edit', $order))
            ->assertSessionHasErrors('purchase_order_id');

        $this->assertTrue($order->fresh()->is_planned);
    }

    public function test_converting_planned_order_with_po_succeeds()
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-GATE-1']);
        $order = Order::factory()->create([
            'order_number' => 'PLN-GATE-2',
            'status' => 'ordered',
            'is_planned' => true,
        ]);

        $this->actingAs($this->superuser())
            ->put(route('orders.update', $order), [
                'order_number' => 'PLN-GATE-2',
                'status' => 'ordered',
                'is_planned' => '0',
                'purchase_order_id' => $po->id,
            ])
            ->assertSessionHasNoErrors();

        $fresh = $order->fresh();
        $this->assertFalse((bool) $fresh->is_planned);
        $this->assertSame($po->id, (int) $fresh->purchase_order_id);
    }
}
