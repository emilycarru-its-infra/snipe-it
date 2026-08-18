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
            ->assertSee(trans('admin/purchase-orders/general.pipeline_needs_po'));
    }

    public function test_processing_and_deploying_are_one_stage_linking_to_the_deployments_board()
    {
        $content = $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.stage_deploying'))
            ->assertSee(trans('admin/purchase-orders/general.pipeline_open_deployments'))
            ->assertSee(route('reports.deployments'))
            ->getContent();

        // The old separate Processing stage is gone from the rail and board.
        $this->assertStringNotContainsString('data-pp-stage="processing"', $content);
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

    public function test_reconciling_column_shows_invoices_the_chevron_counts()
    {
        // A budgeted PO in each year so both are selectable — resolveFiscalYear
        // silently falls back to all-years for a year with no spend, which
        // would make this pass without proving anything.
        PurchaseOrder::factory()->create(['po_number' => 'PO-PIPE-27', 'fiscal_year' => 'FY2026-27', 'budget' => 100.00]);
        PurchaseOrder::factory()->create(['po_number' => 'PO-PIPE-26', 'fiscal_year' => 'FY2025-26', 'budget' => 200.00]);

        // A CDW-ingested order with no fiscal_year stamped, billed inside
        // FY2026-27. The chevron counts it by invoice_date, so the column
        // beneath has to as well — it previously filtered on the order's
        // fiscal_year alone and rendered "Nothing here yet" under a header
        // reading "2 invoices pending approval".
        $order = Order::factory()->create([
            'order_number' => 'ORD-PIPE-NOFY',
            'is_planned' => false,
            'fiscal_year' => null,
        ]);
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-PIPE-NOFY',
            'invoice_date' => '2026-06-11',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee('INV-PIPE-NOFY');
    }

    public function test_the_board_withholds_nothing_behind_a_more_row()
    {
        // Eleven pending invoices used to render six cards and a "+ 5 more"
        // line: the stage's real workload became a number to trust instead
        // of a list to work from, on the one board that exists to show it.
        PurchaseOrder::factory()->create(['po_number' => 'PO-PIPE-27', 'fiscal_year' => 'FY2026-27', 'budget' => 100.00]);
        PurchaseOrder::factory()->create(['po_number' => 'PO-PIPE-26', 'fiscal_year' => 'FY2025-26', 'budget' => 200.00]);

        $order = Order::factory()->create([
            'order_number' => 'ORD-PIPE-MANY',
            'is_planned' => false,
            'fiscal_year' => null,
        ]);

        foreach (range(1, 11) as $n) {
            OrderInvoice::factory()->create([
                'order_id' => $order->id,
                'invoice_number' => 'INV-PIPE-'.$n,
                'invoice_date' => '2026-06-11',
                'approval_status' => 'pending',
            ]);
        }

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk();

        foreach (range(1, 11) as $n) {
            $response->assertSee('INV-PIPE-'.$n);
        }

        $response->assertDontSee('pp-more', false);
    }

    public function test_an_empty_column_says_nothing_rather_than_saying_it_is_empty()
    {
        // A dotted card announcing that a stage has nothing in it is one
        // more thing to read and never an answer to anything.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertDontSee('pp-empty', false)
            // The lock badge and the budgeting definition line went with it:
            // both restated the "Needs PO" chip already on every card.
            ->assertDontSee('pp-gate', false);
    }

    public function test_agreements_are_not_a_card_on_the_deploying_column()
    {
        // Agreements are a report, not an order moving down the pipeline.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertDontSee('data-pp-embed', false);
    }

    public function test_reconciling_column_excludes_invoices_from_another_year()
    {
        PurchaseOrder::factory()->create(['po_number' => 'PO-PIPE-27', 'fiscal_year' => 'FY2026-27', 'budget' => 100.00]);
        PurchaseOrder::factory()->create(['po_number' => 'PO-PIPE-26', 'fiscal_year' => 'FY2025-26', 'budget' => 200.00]);

        $order = Order::factory()->create([
            'order_number' => 'ORD-PIPE-OTHERFY',
            'is_planned' => false,
            'fiscal_year' => null,
        ]);
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-PIPE-OTHERFY',
            'invoice_date' => '2025-06-11',
            'approval_status' => 'pending',
        ]);

        // The date fallback still has to respect the year boundary.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertDontSee('INV-PIPE-OTHERFY');
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
