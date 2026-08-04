<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\AssetCommitted;
use Tests\TestCase;

/**
 * Buyouts, terminations and credits drain (or refill) a purchase order
 * without shipping a device. They ride order_invoices typed accordingly
 * and must fold into the same committed map the dashboard, PO reports and
 * budget carry-forward read.
 */
class PoUsageAdjustmentsTest extends TestCase
{
    private function po(string $number = 'P0090001', float $budget = 50000): PurchaseOrder
    {
        return PurchaseOrder::factory()->create([
            'po_number' => $number,
            'budget' => $budget,
            'fiscal_year' => 'FY2025-26',
        ]);
    }

    private function invoice(PurchaseOrder $po, string $type, float $subtotal, string $date = '2025-10-01'): OrderInvoice
    {
        $order = Order::factory()->create(['purchase_order_id' => $po->id]);

        return OrderInvoice::create([
            'order_id' => $order->id,
            'purchase_order_id' => $po->id,
            'invoice_number' => strtoupper($type).'-'.uniqid(),
            'invoice_date' => $date,
            'subtotal' => $subtotal,
            'invoice_type' => $type,
        ]);
    }

    public function test_buyouts_add_and_credits_subtract_from_committed()
    {
        $po = $this->po();
        Asset::factory()->create(['po_number' => 'P0090001', 'purchase_cost' => 1000, 'purchase_date' => '2025-06-01']);

        $this->invoice($po, 'buyout', 5584.00);
        $this->invoice($po, 'termination', 4480.00);
        // A credit keyed positive must still subtract.
        $this->invoice($po, 'credit', 9488.15);

        $map = AssetCommitted::byPo();

        $this->assertEqualsWithDelta(1000 + 5584.00 + 4480.00 - 9488.15, $map['P0090001'], 0.01);
    }

    public function test_adjustments_scope_to_the_fiscal_year_by_invoice_date()
    {
        $po = $this->po();
        $this->invoice($po, 'buyout', 1000.00, '2025-10-01');
        $this->invoice($po, 'buyout', 2000.00, '2026-10-01');

        $this->assertEqualsWithDelta(1000.00, AssetCommitted::byPo('FY2025-26')['P0090001'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(2000.00, AssetCommitted::byPo('FY2026-27')['P0090001'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(3000.00, AssetCommitted::byPo()['P0090001'] ?? 0, 0.01);
    }

    public function test_untyped_invoices_do_not_touch_committed()
    {
        $po = $this->po();
        $this->invoice($po, 'standard', 7777.00);

        $this->assertArrayNotHasKey('P0090001', AssetCommitted::byPo());
    }

    public function test_ingest_accepts_an_assetless_adjustment_invoice()
    {
        $this->actingAsForApi(User::factory()->superuser()->create());
        $po = $this->po('P0090002');

        $response = $this->postJson(route('api.orders.ingest'), [
            'order_number' => 'BUYOUT-TEST-1',
            'purchase_order_number' => 'P0090002',
            'order_date' => '2026-03-01',
            'invoice' => [
                'invoice_number' => 'BY-1001',
                'invoice_date' => '2026-03-01',
                'subtotal' => 4480.00,
                'invoice_type' => 'buyout',
                'contract_reference' => 'LEASE-REF-1',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('order_invoices', [
            'invoice_number' => 'BY-1001',
            'invoice_type' => 'buyout',
            'contract_reference' => 'LEASE-REF-1',
            'purchase_order_id' => $po->id,
        ]);
        $this->assertEqualsWithDelta(4480.00, AssetCommitted::byPo()['P0090002'] ?? 0, 0.01);
    }

    public function test_ingest_still_requires_items_for_standard_orders()
    {
        $this->actingAsForApi(User::factory()->superuser()->create());

        $response = $this->postJson(route('api.orders.ingest'), [
            'order_number' => 'STD-TEST-1',
            'invoice' => [
                'invoice_number' => 'INV-1',
                'subtotal' => 100.00,
            ],
        ]);

        $response->assertJsonPath('status', 'error');
    }
}
