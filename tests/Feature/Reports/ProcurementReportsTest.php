<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

class ProcurementReportsTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_reports_landing_page_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk();
    }

    public function test_po_budget_report_lists_purchase_orders()
    {
        PurchaseOrder::factory()->create(['po_number' => 'PO-REPORT-1', 'budget' => 5000]);

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.po-budget'));

        $response->assertOk();
        $this->assertStringContainsString('PO-REPORT-1', $response->streamedContent());
    }

    public function test_invoice_report_lists_invoices()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        OrderInvoice::factory()->create(['order_id' => $order->id, 'invoice_number' => 'INV-REPORT-1']);

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.invoices'));

        $response->assertOk();
        $this->assertStringContainsString('INV-REPORT-1', $response->streamedContent());
    }

    public function test_receiving_report_lists_orders()
    {
        $order = Order::factory()->create(['order_number' => 'ORD-REPORT-1', 'status' => 'ordered']);

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.receiving'));

        $response->assertOk();
        $this->assertStringContainsString('ORD-REPORT-1', $response->streamedContent());
    }

    public function test_tax_report_downloads()
    {
        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.tax'));

        $response->assertOk();
        $this->assertStringContainsString('GST', $response->streamedContent());
    }

    public function test_capital_report_downloads()
    {
        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.capital'));

        $response->assertOk();
        $this->assertStringContainsString('Fiscal Year', $response->streamedContent());
    }

    public function test_refresh_forecast_report_lists_assets_near_eol()
    {
        $asset = Asset::factory()->create(['asset_tag' => 'FORECAST-1']);
        // The asset factory recomputes asset_eol_date in an afterMaking hook,
        // so pin it directly to a date inside the forecast window.
        Asset::query()->whereKey($asset->id)
            ->update(['asset_eol_date' => now()->addMonths(6)->format('Y-m-d')]);

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.forecast'));

        $response->assertOk();
        $this->assertStringContainsString('FORECAST-1', $response->streamedContent());
    }
}
