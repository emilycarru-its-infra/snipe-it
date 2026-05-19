<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\PurchaseOrder;
use Tests\TestCase;

class ImportProcurementTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'proc').'.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function reconciliationCsv(): string
    {
        return $this->csv(<<<CSV
        PO,PO Budget,Schedule,Qty,Item Description,CDW Order,CDW Invoice,Subtotal,GST 5%,PST 7%,Status
        PO-TEST-1,10000,003,2,Test Laptop,ORD-A,INV-1,2000,100,140,Invoiced
        PO-TEST-1,,003,1,Test Desktop,ORD-A,INV-2,1000,50,70,Invoiced
        PO-TEST-2,5000,007,3,Future Tablet,ORD-PLAN,,3000,150,210,Pending
        ,,,,GRAND TOTAL,,,,6000,,,
        CSV);
    }

    private function invoicesCsv(): string
    {
        return $this->csv(<<<CSV
        Order #,Invoice #,Invoice Date,Invoice SubTotal,Invoice Shipping Cost,Invoice Sales Tax,Invoice Total
        ORD-A,CDWINV-1,7/22/2025,"\$2,000.00",\$0.00,\$240.00,"\$2,240.00"
        ORD-A,CDWINV-1,7/22/2025,"\$2,000.00",\$0.00,\$240.00,"\$2,240.00"
        ORD-A,CDWINV-2,8/1/2025,\$1000.00,\$0.00,\$120.00,\$1120.00
        CSV);
    }

    public function test_creates_purchase_orders_with_budgets()
    {
        $this->artisan('procurement:import', ['--reconciliation' => $this->reconciliationCsv()])
            ->assertExitCode(0);

        $po = PurchaseOrder::where('po_number', 'PO-TEST-1')->first();
        $this->assertNotNull($po);
        $this->assertEquals(10000.0, (float) $po->budget);
        $this->assertEquals('FY2025-26', $po->fiscal_year);
    }

    public function test_links_existing_order_to_its_primary_purchase_order()
    {
        $order = Order::factory()->create(['order_number' => 'ORD-A', 'status' => 'received']);

        $this->artisan('procurement:import', ['--reconciliation' => $this->reconciliationCsv()])
            ->assertExitCode(0);

        $po = PurchaseOrder::where('po_number', 'PO-TEST-1')->first();
        $this->assertEquals($po->id, $order->fresh()->purchase_order_id);
    }

    public function test_creates_pending_items_as_planned_orders()
    {
        $this->artisan('procurement:import', ['--reconciliation' => $this->reconciliationCsv()])
            ->assertExitCode(0);

        $planned = Order::where('order_number', 'ORD-PLAN')->first();
        $this->assertNotNull($planned);
        $this->assertTrue((bool) $planned->is_planned);
        $this->assertEquals('FY2026-27', $planned->fiscal_year);
        $this->assertEquals(1, $planned->items()->count());
        // 3 units at a 3000 subtotal.
        $this->assertEquals(1000.0, (float) $planned->items()->first()->unit_cost);
    }

    public function test_creates_one_invoice_per_cdw_invoice_number()
    {
        $order = Order::factory()->create(['order_number' => 'ORD-A', 'status' => 'received']);

        $this->artisan('procurement:import', ['--invoices' => $this->invoicesCsv()])
            ->assertExitCode(0);

        // CDWINV-1 appears on two rows but is recorded once.
        $this->assertEquals(2, OrderInvoice::where('order_id', $order->id)->count());

        $invoice = OrderInvoice::where('invoice_number', 'CDWINV-1')->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(2000.0, (float) $invoice->subtotal);
        $this->assertEquals(2240.0, (float) $invoice->total);
        // BC tax 240 splits 5:7 into GST 100 / PST 140.
        $this->assertEquals(100.0, (float) $invoice->tax_gst);
        $this->assertEquals(140.0, (float) $invoice->tax_pst);
    }

    public function test_invoices_for_unknown_orders_are_skipped()
    {
        $this->artisan('procurement:import', ['--invoices' => $this->invoicesCsv()])
            ->assertExitCode(0);

        $this->assertEquals(0, OrderInvoice::count());
    }
}
