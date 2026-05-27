<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use App\Models\CustomField;
use App\Models\UserAgreement;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Statuslabel;
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

    public function test_procurement_dashboard_renders_with_summary_and_charts()
    {
        PurchaseOrder::factory()->create(['po_number' => 'PO-DASH-1', 'budget' => 25000]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.dashboard_title'))
            ->assertSee('procPoChart')
            ->assertSee('procMonthlyChart');
    }

    public function test_dashboard_filters_by_fiscal_year()
    {
        PurchaseOrder::factory()->create(['po_number' => 'PO-FY25', 'fiscal_year' => 'FY2025-26', 'budget' => 10000]);
        PurchaseOrder::factory()->create(['po_number' => 'PO-FY26', 'fiscal_year' => 'FY2026-27', 'budget' => 20000]);
        $superuser = $this->superuser();

        // Unfiltered, both purchase orders are charted.
        $this->actingAs($superuser)
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertSee('PO-FY25')
            ->assertSee('PO-FY26');

        // Filtered to one fiscal year, only that year's PO appears.
        $this->actingAs($superuser)
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2025-26']))
            ->assertOk()
            ->assertSee('PO-FY25')
            ->assertDontSee('PO-FY26');
    }

    public function test_po_budget_report_renders_live_and_as_csv()
    {
        PurchaseOrder::factory()->create(['po_number' => 'PO-REPORT-1', 'budget' => 5000]);
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->get(route('reports.procurement.po-budget'))
            ->assertOk()
            ->assertSee('PO-REPORT-1')
            // Money cells render in accounting format: $ sign, thousands separator, two decimals.
            ->assertSee('$5,000.00');

        $csv = $this->actingAs($superuser)
            ->get(route('reports.procurement.po-budget', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('PO-REPORT-1', $csv->streamedContent());
    }

    public function test_invoice_report_renders_live_and_as_csv()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        OrderInvoice::factory()->create(['order_id' => $order->id, 'invoice_number' => 'INV-REPORT-1']);
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->get(route('reports.procurement.invoices'))
            ->assertOk()
            ->assertSee('INV-REPORT-1');

        $csv = $this->actingAs($superuser)
            ->get(route('reports.procurement.invoices', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('INV-REPORT-1', $csv->streamedContent());
    }

    public function test_capital_report_renders_live_and_as_csv()
    {
        PurchaseOrder::factory()->create(['fiscal_year' => 'FY2025-26', 'budget' => 1000]);
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->get(route('reports.procurement.capital'))
            ->assertOk()
            ->assertSee('FY2025-26');

        $csv = $this->actingAs($superuser)
            ->get(route('reports.procurement.capital', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('Fiscal Year', $csv->streamedContent());
    }

    public function test_refresh_forecast_report_renders_live_and_as_csv()
    {
        $asset = Asset::factory()->create(['asset_tag' => 'FORECAST-1']);
        // The asset factory recomputes asset_eol_date in an afterMaking hook,
        // so pin it directly to a date inside the forecast window.
        Asset::query()->whereKey($asset->id)
            ->update(['asset_eol_date' => now()->addMonths(6)->format('Y-m-d')]);
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->get(route('reports.procurement.forecast'))
            ->assertOk()
            ->assertSee('FORECAST-1');

        $csv = $this->actingAs($superuser)
            ->get(route('reports.procurement.forecast', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('FORECAST-1', $csv->streamedContent());
    }

    public function test_receiving_report_downloads()
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

    public function test_leases_operational_report_renders_without_lease_data()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.leases-operational'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_leases_operational'));
    }

    public function test_leases_financial_report_renders_without_lease_data()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.leases-financial'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_leases_financial'));
    }

    public function test_csi_schedule_report_renders_without_lease_data()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.csi-schedule'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_csi_schedule'));
    }

    public function test_leases_operational_report_groups_assets_by_contract()
    {
        $contractField = CustomField::factory()->create(['name' => 'Lease Contract ID']);
        $contractColumn = $contractField->db_column;

        $active = Statuslabel::factory()->rtd()->create();

        $asset = Asset::factory()->create([
            'asset_tag' => 'LEASE-OP-1',
            'status_id' => $active->id,
        ]);
        Asset::query()->whereKey($asset->id)->update([$contractColumn => '301452-003']);

        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->get(route('reports.procurement.leases-operational'))
            ->assertOk()
            // CSI Leasing is the provider for any 301452-* schedule.
            ->assertSee('301452-003')
            ->assertSee('CSI Leasing');

        $csv = $this->actingAs($superuser)
            ->get(route('reports.procurement.leases-operational', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('301452-003', $csv->streamedContent());
    }

    public function test_csi_schedule_report_skips_non_csi_contracts()
    {
        $contractField = CustomField::factory()->create(['name' => 'Lease Contract ID']);
        $contractColumn = $contractField->db_column;
        $active = Statuslabel::factory()->rtd()->create();

        $csi = Asset::factory()->create(['asset_tag' => 'CSI-1', 'status_id' => $active->id]);
        Asset::query()->whereKey($csi->id)->update([$contractColumn => '301452-004']);

        $eci = Asset::factory()->create(['asset_tag' => 'ECI-1', 'status_id' => $active->id]);
        Asset::query()->whereKey($eci->id)->update([$contractColumn => 'ECI20220901']);

        // The CSI Schedule report is scoped to 301452-* leases only —
        // ECI contracts belong to the Macquarie reconciliation.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.csi-schedule'))
            ->assertOk()
            ->assertSee('301452-004')
            ->assertDontSee('ECI20220901');
    }

    public function test_invoice_approval_queue_renders_pending_invoices_with_variance()
    {
        $order = Order::factory()->create(['order_number' => 'PMCN-AP-1']);
        $invoice = OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-AP-1',
            'subtotal' => 1234.56,
            'approval_status' => 'pending',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.invoice-approval'))
            ->assertOk()
            ->assertSee('INV-AP-1')
            ->assertSee('PMCN-AP-1')
            // No line items → expected = $0 and variance = invoice subtotal,
            // so the row gets the danger class and shows the full amount.
            ->assertSee('$1,234.56');
    }

    public function test_invoice_approval_queue_hides_approved_by_default()
    {
        $order = Order::factory()->create();
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-AP-APPROVED',
            'approval_status' => 'approved',
        ]);
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-AP-PENDING',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.invoice-approval'))
            ->assertOk()
            ->assertSee('INV-AP-PENDING')
            ->assertDontSee('INV-AP-APPROVED');
    }

    public function test_invoice_approval_patch_marks_invoice_approved()
    {
        $order = Order::factory()->create();
        $invoice = OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'approval_status' => 'pending',
        ]);

        $superuser = $this->superuser();
        $this->actingAs($superuser)
            ->patch(route('reports.procurement.invoice-approval.update', $invoice), [
                'approval_status' => 'approved',
                'is_final_invoice' => true,
                'usage_tag' => 'Curriculum',
            ])
            ->assertRedirect();

        $invoice->refresh();
        $this->assertEquals('approved', $invoice->approval_status);
        $this->assertTrue($invoice->is_final_invoice);
        $this->assertEquals('Curriculum', $invoice->usage_tag);
        $this->assertEquals($superuser->id, $invoice->approved_by);
        $this->assertNotNull($invoice->approved_at);
    }

    public function test_lease_decisions_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.lease-decisions'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_lease_decisions'));
    }

    public function test_po_disposition_report_renders_with_recommendation()
    {
        // Budget greater than committed and no open orders → "Reallocate
        // surplus", which is the disposition Mark looks for at year end.
        PurchaseOrder::factory()->create([
            'po_number' => 'PO-DISP-SURPLUS',
            'budget' => 5000,
            'fiscal_year' => 'FY2025-26',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.po-disposition'))
            ->assertOk()
            ->assertSee('PO-DISP-SURPLUS')
            ->assertSee(trans('admin/purchase-orders/general.disposition_reallocate'));
    }

    public function test_extension_watch_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.extension-watch'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_extension_watch'));
    }

    public function test_aro_register_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.aro-register'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_aro_register'));
    }

    public function test_asset_lease_detail_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.asset-lease-detail'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_asset_lease_detail'));
    }

    public function test_po_drilldown_report_walks_po_order_invoice_chain()
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-DRILL-1', 'budget' => 10000]);
        $order = Order::factory()->create([
            'order_number' => 'PMCN-DRILL-1',
            'purchase_order_id' => $po->id,
        ]);
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-DRILL-1',
            'subtotal' => 999.99,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.po-drilldown'))
            ->assertOk()
            ->assertSee('PO-DRILL-1')
            ->assertSee('PMCN-DRILL-1')
            ->assertSee('INV-DRILL-1');
    }

    public function test_invoice_approval_queue_filters_by_attestation_type()
    {
        $order = Order::factory()->create();
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-VENDOR-OKP',
            'attestation_type' => 'vendor_invoice',
            'approval_status' => 'pending',
        ]);
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-LESSOR-OKP',
            'attestation_type' => 'lessor_okp',
            'approval_status' => 'pending',
        ]);

        // Asking for the lessor-OKP filter shows only the CSI attestation
        // and hides the regular vendor invoice.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.invoice-approval', ['attestation_type' => 'lessor_okp']))
            ->assertOk()
            ->assertSee('INV-LESSOR-OKP')
            ->assertDontSee('INV-VENDOR-OKP');
    }

    public function test_user_agreement_ledger_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.user-agreement-ledger'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_user_agreement_ledger'));
    }

    public function test_user_agreement_ledger_shows_lifecycle_and_balance()
    {
        $user = User::factory()->create(['first_name' => 'Carlo', 'last_name' => 'Ghioni']);
        UserAgreement::create([
            'agreement_type' => 'upgrade',
            'user_id' => $user->id,
            'lifecycle_stage' => 'in_repayment',
            'base_program_price' => 2200,
            'device_cost' => 3400,
            'top_up_amount' => 1200,
            'payment_method' => 'payroll_deduction',
            'installment_count' => 24,
            'installment_amount' => 50,
            'balance_paid' => 200,
            'balance_remaining' => 1000,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.user-agreement-ledger'))
            ->assertOk()
            ->assertSee('Carlo Ghioni')
            ->assertSee(trans('admin/purchase-orders/general.user_agreement_stage_value_in_repayment'))
            ->assertSee('$1,200.00')
            ->assertSee('$1,000.00');
    }

    public function test_user_agreement_ledger_filters_by_agreement_type()
    {
        $user = User::factory()->create();
        UserAgreement::create([
            'agreement_type' => 'upgrade',
            'user_id' => $user->id,
            'lifecycle_stage' => 'agreement_signed',
            'top_up_amount' => 500,
        ]);
        UserAgreement::create([
            'agreement_type' => 'lease_end_purchase',
            'user_id' => $user->id,
            'lifecycle_stage' => 'closed_buyout',
            'buyout_cost' => 800,
            'old_asset_tag' => 'F-OLD-1',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.user-agreement-ledger', ['agreement_type' => 'lease_end_purchase']))
            ->assertOk()
            ->assertSee('$800.00')
            ->assertDontSee('$500.00');
    }

    public function test_dashboard_shows_user_agreements_unsigned_card()
    {
        $user = User::factory()->create();
        UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'agreement_sent',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.card_user_agreements_unsigned', ['count' => 1]));
    }

    public function test_disposition_grid_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_disposition_grid'));
    }

    public function test_credit_ledger_excludes_regular_invoices()
    {
        $order = Order::factory()->create();
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-REGULAR-1',
            'invoice_type' => 'regular',
        ]);
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-CREDIT-1',
            'invoice_type' => 'credit',
            'contract_reference' => '301452-003',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.credit-ledger'))
            ->assertOk()
            ->assertSee('INV-CREDIT-1')
            ->assertDontSee('INV-REGULAR-1');
    }

    public function test_lessor_breakdown_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.lessor-breakdown'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_lessor_breakdown'));
    }

    public function test_pst_applicability_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.pst-applicability'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_pst_applicability'));
    }

    public function test_dashboard_shows_pending_approval_and_decision_cards()
    {
        $order = Order::factory()->create();
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'approval_status' => 'pending',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            // Card copy uses the lang strings introduced for the new
            // finance-facing cards.
            ->assertSee(trans('admin/purchase-orders/general.card_pending_approvals', ['count' => 1]))
            ->assertSee(trans('admin/purchase-orders/general.card_pending_decisions', ['count' => 0]));
    }

    public function test_leases_financial_report_rolls_up_warranty_costs()
    {
        $contractField = CustomField::factory()->create(['name' => 'Lease Contract ID']);
        $contractColumn = $contractField->db_column;
        $active = Statuslabel::factory()->rtd()->create();

        $asset = Asset::factory()->create([
            'asset_tag' => 'LEASE-FIN-1',
            'status_id' => $active->id,
            'purchase_cost' => 1000.00,
        ]);
        Asset::query()->whereKey($asset->id)->update([$contractColumn => '301452-003']);

        $order = Order::factory()->create(['order_number' => 'PMCN-FIN-1']);
        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $asset->id,
            'description' => 'Mac mini lease line',
            'quantity' => 1,
            'unit_cost' => 1000.00,
            'warranty_cost' => 155.70,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.leases-financial'))
            ->assertOk()
            ->assertSee('PMCN-FIN-1')
            ->assertSee('$155.70')
            ->assertSee('$1,155.70');
    }

    /**
     * Creates a GL transaction row directly (no factory — the model is a
     * plain ledger row populated at checkout time).
     */
    private function glTransaction(array $overrides = []): ConsumableTransaction
    {
        return ConsumableTransaction::create(array_merge([
            'consumable_id' => Consumable::factory()->create()->id,
            'asset_id' => Asset::factory()->create()->id,
            'gl_code' => '6100-100',
            'quantity' => 1,
            'unit_cost' => 100,
            'total_cost' => 100,
            'transaction_date' => '2026-05-01',
            'fiscal_year' => 'FY2026-27',
            'status' => ConsumableTransaction::STATUS_DRAFT,
        ], $overrides));
    }

    public function test_gl_journal_transfer_report_groups_by_gl_with_subtotals()
    {
        $this->glTransaction(['gl_code' => '6100-100', 'total_cost' => 100]);
        $this->glTransaction(['gl_code' => '6100-100', 'total_cost' => 100, 'transaction_date' => '2026-05-02']);
        $this->glTransaction(['gl_code' => '6200-200', 'total_cost' => 50, 'transaction_date' => '2026-05-03']);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.gl-transfer'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_gl_transfer'))
            ->assertSee('6100-100')
            ->assertSee('6200-200')
            ->assertSee('$200.00')   // 6100-100 subtotal
            ->assertSee('$250.00');  // grand total
    }

    public function test_gl_journal_transfer_exports_csv()
    {
        $this->glTransaction(['gl_code' => '6100-100']);

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.gl-transfer', ['format' => 'csv']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
    }

    public function test_gl_journal_transfer_mark_posted_flips_draft_transactions()
    {
        $txn = $this->glTransaction(['status' => ConsumableTransaction::STATUS_DRAFT]);

        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.gl-transfer.post'), ['fiscal_year' => 'FY2026-27'])
            ->assertRedirect(route('reports.procurement.gl-transfer', ['fiscal_year' => 'FY2026-27']));

        $this->assertEquals(ConsumableTransaction::STATUS_POSTED, $txn->fresh()->status);
    }

    public function test_gl_journal_transfer_mark_transferred_flips_posted_transactions()
    {
        $posted = $this->glTransaction(['status' => ConsumableTransaction::STATUS_POSTED]);
        $draft = $this->glTransaction(['status' => ConsumableTransaction::STATUS_DRAFT]);

        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.gl-transfer.transfer'), ['fiscal_year' => 'FY2026-27'])
            ->assertRedirect(route('reports.procurement.gl-transfer', ['fiscal_year' => 'FY2026-27']));

        // posted → transferred; a draft row is untouched (only posted advances)
        $this->assertEquals(ConsumableTransaction::STATUS_TRANSFERRED, $posted->fresh()->status);
        $this->assertEquals(ConsumableTransaction::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_updating_visibility_persists_the_users_hidden_reports()
    {
        $user = $this->superuser();

        $this->actingAs($user)
            ->patchJson(route('reports.procurement.visibility'), [
                'hidden' => ['report_po_budget', 'report_invoices'],
            ])
            ->assertOk()
            ->assertJson(['hidden' => ['report_po_budget', 'report_invoices']]);

        $this->assertEquals(
            ['report_po_budget', 'report_invoices'],
            $user->fresh()->hidden_procurement_reports
        );
    }

    public function test_reports_landing_filters_hidden_reports_and_shows_restore_link()
    {
        $user = $this->superuser();
        $user->hidden_procurement_reports = ['report_po_budget'];
        $user->save();

        $this->actingAs($user)
            ->get(route('reports.procurement'))
            ->assertOk()
            // "1 hidden — show all" surfaces above the list when anything is hidden.
            ->assertSee(trans('admin/purchase-orders/general.reports_hidden_count', ['count' => 1]))
            // The hidden report's link is not rendered in the table.
            ->assertDontSee('href="'.route('reports.procurement.po-budget').'"', false);
    }

    public function test_visibility_endpoint_accepts_an_empty_list_to_restore_all()
    {
        $user = $this->superuser();
        $user->hidden_procurement_reports = ['report_po_budget'];
        $user->save();

        $this->actingAs($user)
            ->patchJson(route('reports.procurement.visibility'), ['hidden' => []])
            ->assertOk()
            ->assertJson(['hidden' => []]);

        $this->assertEquals([], $user->fresh()->hidden_procurement_reports);
    }
}
