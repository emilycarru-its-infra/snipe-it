<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\LeaseDecision;
use App\Models\UserAgreement;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Statuslabel;
use App\Models\Supplier;
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

        // ?fiscal_year=all opts out of the current-FY default (PR #141)
        // and charts every year.
        $this->actingAs($superuser)
            ->get(route('reports.procurement', ['fiscal_year' => 'all']))
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
            ->assertRedirect(route('reports.procurement.leases-operational', ['fiscal_year' => 'all']));

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.leases-operational', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_leases_operational'))
            // Charts above the table, and every column but Models nowrapped.
            ->assertSee('leases-assets-per-contract', false)
            ->assertSee('leases-ownership-mix', false)
            ->assertSee('rpt-nowrap-tail', false);

        // The path says "leases", the old spellings walk over.
        $this->actingAs($this->superuser())->get('/procurement/leases-hardware?fiscal_year=all')->assertOk();
        $this->actingAs($this->superuser())->get('/procurement/leases-operational')->assertRedirect('/procurement/leases-hardware');
        $this->actingAs($this->superuser())->get('/procurement/leases-financial')->assertRedirect('/procurement/leases-contracts');
    }

    public function test_leases_financial_report_renders_without_lease_data()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.leases-financial', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_leases_financial'))
            // Column headers are the human-readable labels, not the raw
            // `_snipeit_*` generated DB column names (regression: the header
            // row was being clobbered by the field-column lookup map).
            ->assertSee(trans('admin/purchase-orders/general.lease_contract_id'))
            ->assertDontSee('_snipeit_lease_contract_id')
            ->assertSee('leases-cost-per-contract', false)
            ->assertSee('leases-cost-by-lessor', false);
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
        $active = Statuslabel::factory()->rtd()->create();

        $asset = Asset::factory()->create([
            'asset_tag' => 'LEASE-OP-1',
            'status_id' => $active->id,
        ]);
        Asset::query()->whereKey($asset->id)->update(['lease_contract_id' => '301452-003']);

        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->get(route('reports.procurement.leases-operational', ['fiscal_year' => 'all']))
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
        $active = Statuslabel::factory()->rtd()->create();

        $csi = Asset::factory()->create(['asset_tag' => 'CSI-1', 'status_id' => $active->id]);
        Asset::query()->whereKey($csi->id)->update(['lease_contract_id' => '301452-004']);

        $eci = Asset::factory()->create(['asset_tag' => 'ECI-1', 'status_id' => $active->id]);
        Asset::query()->whereKey($eci->id)->update(['lease_contract_id' => 'ECI20220901']);

        // The CSI Schedule report is scoped to 301452-* leases only —
        // ECI contracts belong to the CCA Financial reconciliation.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.csi-schedule'))
            ->assertOk()
            ->assertSee('301452-004')
            ->assertDontSee('ECI20220901');
    }

    public function test_csi_schedule_warranty_falls_back_to_the_asset_column()
    {
        $active = Statuslabel::factory()->rtd()->create();

        // The real CSI schedules carry warranty on the asset (warranty_soft_cost)
        // while the matching order_items.warranty_cost is 0 — reading the order
        // item alone reported $0.00 warranty on every line and understated
        // FY2025-26 by $30,051.28 against Leases (Financial) over the same assets.
        $asset = Asset::factory()->create([
            'asset_tag' => 'CSI-WARR-1',
            'status_id' => $active->id,
            'purchase_cost' => 2453.00,
            'purchase_date' => '2025-06-01',
        ]);
        Asset::query()->whereKey($asset->id)->update([
            'lease_contract_id' => '301452-003',
            'warranty_soft_cost' => '232.50',
        ]);

        $order = Order::factory()->create(['order_number' => 'PMCN-WARR-1', 'fiscal_year' => 'FY2025-26']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $asset->id,
            'description' => 'iMac lease line',
            'quantity' => 1,
            'unit_cost' => 2453.00,
            'warranty_cost' => 0.00,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.csi-schedule', ['fiscal_year' => 'FY2025-26']))
            ->assertOk()
            ->assertSee('301452-003')
            ->assertSee('$232.50')     // unit warranty, off the asset column
            ->assertSee('$2,685.50');  // line total = equipment + warranty
    }

    public function test_report_tables_open_assets_and_users_in_the_lightbox()
    {
        $asset = Asset::factory()->create(['asset_tag' => 'LIGHTBOX-1']);
        Asset::query()->whereKey($asset->id)
            ->update(['asset_eol_date' => now()->addMonths(6)->format('Y-m-d')]);
        $superuser = $this->superuser();

        // The forecast table links the asset cells into the lightbox…
        $this->actingAs($superuser)
            ->get(route('reports.procurement.forecast'))
            ->assertOk()
            ->assertSee('js-lightbox')
            ->assertSee(route('hardware.show', $asset->id), false)
            // …and the layout carries the lightbox host + framed hook.
            ->assertSee('id="app-lightbox"', false)
            ->assertSee('html.framed', false);

        // The links map is render-time only — exports carry clean cells.
        $csv = $this->actingAs($superuser)
            ->get(route('reports.procurement.forecast', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringNotContainsString('js-lightbox', $csv->streamedContent());
        $this->assertStringNotContainsString('href', $csv->streamedContent());
    }

    public function test_lease_reports_lead_with_the_lessor_column()
    {
        $active = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create(['asset_tag' => 'LESSOR-COL-1', 'status_id' => $active->id]);
        Asset::query()->whereKey($asset->id)->update(['lease_contract_id' => '301452-004']);

        $superuser = $this->superuser();

        foreach (['reports.procurement.leases-operational', 'reports.procurement.leases-financial'] as $routeName) {
            $csv = $this->actingAs($superuser)->get(route($routeName, ['format' => 'csv']));
            $csv->assertOk();
            $lines = explode("\n", trim($csv->streamedContent()));
            // Column A is the lessor, labelled with the standard name.
            $this->assertStringStartsWith(
                trans('admin/purchase-orders/general.lease_provider'),
                ltrim($lines[0], "\xEF\xBB\xBF"),
                $routeName
            );
        }

        $this->assertSame('Lessor', trans('admin/purchase-orders/general.lease_provider'));
    }

    public function test_csi_schedule_report_leads_with_the_lessor_column()
    {
        $active = Statuslabel::factory()->rtd()->create();
        $lessor = \App\Models\Supplier::factory()->create(['name' => 'CSI Leasing Canada']);

        $asset = Asset::factory()->create(['asset_tag' => 'CSI-LESSOR-1', 'status_id' => $active->id]);
        Asset::query()->whereKey($asset->id)
            ->update(['lease_contract_id' => '301452-004', 'lessor_id' => $lessor->id]);

        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->get(route('reports.procurement.csi-schedule'))
            ->assertOk()
            ->assertSee(trans('general.lessor'))
            ->assertSee('CSI Leasing Canada');

        // Column A of the export is the lessor.
        $csv = $this->actingAs($superuser)
            ->get(route('reports.procurement.csi-schedule', ['format' => 'csv']));
        $csv->assertOk();
        $lines = explode("\n", trim($csv->streamedContent()));
        $this->assertStringStartsWith(trans('general.lessor'), ltrim($lines[0], "\xEF\xBB\xBF"));
        $this->assertStringContainsString('CSI Leasing Canada', $csv->streamedContent());
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

    public function test_lease_decisions_report_exposes_an_editable_note_pencil()
    {
        LeaseDecision::factory()->create([
            'contract_reference' => 'ECI20230701',
            'decision_type' => 'return',
            'notes' => 'Pickup booked.',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.lease-decisions'))
            ->assertOk()
            ->assertSee('Pickup booked.')
            // The inline-edit pencil cell is rendered for editors.
            ->assertSee('rpt-note-edit')
            ->assertSee('data-model="lease_decision"', false);
    }

    public function test_report_note_endpoint_updates_a_lease_decision_note()
    {
        $decision = LeaseDecision::factory()->create([
            'contract_reference' => 'ECI20230701',
            'decision_type' => 'return',
            'notes' => 'old',
        ]);

        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.note'), [
                'model' => 'lease_decision',
                'id' => $decision->id,
                'notes' => 'updated inline',
            ])
            ->assertOk()
            ->assertJson(['status' => 'success', 'notes' => 'updated inline']);

        $this->assertDatabaseHas('lease_decisions', [
            'id' => $decision->id,
            'notes' => 'updated inline',
        ]);
    }

    public function test_report_note_endpoint_rejects_unknown_model()
    {
        // The fork wraps validation failures as 200 + {status:error}.
        $this->actingAs($this->superuser())
            ->postJson(route('reports.procurement.note'), [
                'model' => 'order_invoice',
                'id' => 1,
                'notes' => 'nope',
            ])
            ->assertOk()
            ->assertJson(['status' => 'error']);
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

    public function test_extension_watch_covers_the_decision_window_around_the_lease_end()
    {
        // Lapsed two months ago with the device still out — a live holdover.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20220201',
            'Lease End Date' => now()->subMonths(2)->format('Y-m-d'),
        ], ['asset_tag' => 'EXT-LAPSED', 'purchase_date' => '2022-02-01']);

        // Ends next month — needs a renew/return/buy decision now.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20220901',
            'Lease End Date' => now()->addMonth()->format('Y-m-d'),
        ], ['asset_tag' => 'EXT-ENDING', 'purchase_date' => '2022-09-01']);

        // Years of term left. Previously listed because a 48-month guess off
        // the purchase date had "elapsed", though the lease runs to 2031.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20250101',
            'Lease End Date' => '2031-01-01',
        ], ['asset_tag' => 'EXT-FUTURE', 'purchase_date' => '2025-01-01']);

        // Ended years ago with a device never checked in. A records gap for
        // Lease Data Health, not a lease still being negotiated — carrying it
        // here is what made the report unreadable.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20180101',
            'Lease End Date' => '2024-01-01',
        ], ['asset_tag' => 'EXT-ANCIENT', 'purchase_date' => '2018-01-01']);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.extension-watch'))
            ->assertOk()
            ->assertSee('ECI20220201')
            ->assertSee('ECI20220901')
            ->assertDontSee('ECI20250101')
            ->assertDontSee('ECI20180101');
    }

    public function test_extension_watch_drops_a_lease_whose_devices_all_went_back()
    {
        // A decommission date plus an archived return status completes the
        // lease lifecycle. ECI20210601A was 23 of 23 in exactly this state and
        // still rendered as the report's worst row, at 25 months extended.
        $returned = Statuslabel::factory()->archived()->create();
        $asset = Asset::factory()->create(['status_id' => $returned->id, 'purchase_date' => '2021-06-01']);
        Asset::query()->whereKey($asset->id)->update([
            'lease_contract_id' => 'ECI20210601A',
            'lease_end_date' => now()->subMonths(2)->format('Y-m-d'),
            'decommission_date' => now()->subMonths(2)->format('Y-m-d'),
            'ownership_type' => 'Lease to Return',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.extension-watch'))
            ->assertOk()
            ->assertDontSee('ECI20210601A');
    }

    public function test_extension_watch_lists_the_devices_still_on_a_lease()
    {
        // The point of the report is knowing which units to chase.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20220201',
            'Lease End Date' => now()->subMonths(2)->format('Y-m-d'),
        ], ['asset_tag' => 'EXT-DETAIL-1', 'serial' => 'EXTDETAILSERIAL1', 'purchase_date' => '2022-02-01']);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.extension-watch'))
            ->assertOk()
            ->assertSee('EXT-DETAIL-1')
            ->assertSee('EXTDETAILSERIAL1');
    }

    public function test_aro_register_report_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.aro-register'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_aro_register'));
    }

    public function test_aro_register_shows_lease_to_own_as_retained_at_no_cost()
    {
        // A lease-to-own contract with a logged buyout decision — the
        // equipment is kept at term end, so the register shows the retention
        // as an explicit zero-cost Retained row: no return obligation, no
        // buyout cost, and neither the decision amount nor the per-asset
        // Buyout Cost field may leak into the total.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20221201',
            'Ownership Type' => 'Lease to Own',
            'Buyout Cost' => '5000',
        ], ['asset_tag' => 'ARO-LTO']);
        LeaseDecision::factory()->create([
            'contract_reference' => 'ECI20221201',
            'decision_type' => 'buyout',
            'status' => 'approved',
            'amount' => 5000,
        ]);

        // A normal returnable contract with a return decision — this one is a
        // real obligation and should show with its cost.
        LeaseDecision::factory()->create([
            'contract_reference' => 'ECI20230701',
            'decision_type' => 'return',
            'status' => 'approved',
            'amount' => 250,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.aro-register'))
            ->assertOk()
            ->assertSee('ECI20230701')
            ->assertSee('ECI20221201')
            ->assertSee(trans('admin/purchase-orders/general.aro_action_retained'))
            ->assertDontSee('$5,000.00');
    }

    public function test_aro_register_waits_for_the_decision_window_on_lease_to_own()
    {
        // A lease-to-own contract five years from term end has made no
        // decision yet — "kept at term end" is a prediction, not a fact, and
        // 301452-008 (ending 2031) was reading as already settled. No logged
        // decision, term end far out: the register stays silent about it.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-FAR-OUT',
            'Ownership Type' => 'Lease to Own',
            'Lease End Date' => now()->addYears(5)->format('Y-m-d'),
        ]);

        // The same shape at term end is exactly what the Retained row is
        // for, logged decision or not.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-AT-TERM',
            'Ownership Type' => 'Lease to Own',
            'Lease End Date' => now()->addMonth()->format('Y-m-d'),
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.aro-register'))
            ->assertOk()
            ->assertSee('ECI-AT-TERM')
            ->assertDontSee('ECI-FAR-OUT');
    }

    public function test_aro_register_offers_edit_and_delete_on_logged_decisions()
    {
        $decision = LeaseDecision::factory()->create([
            'contract_reference' => 'ECI20230701',
            'decision_type' => 'return',
            'status' => 'approved',
            'amount' => 250,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.aro-register'))
            ->assertOk()
            ->assertSee(route('lease-decisions.edit', $decision->id), false)
            ->assertSee(route('lease-decisions.destroy', $decision->id), false);
    }

    public function test_agreements_hub_lives_at_procurement_agreements()
    {
        $this->actingAs($this->superuser())
            ->get('/procurement/agreements')
            ->assertOk();

        $this->actingAs($this->superuser())
            ->get('/procurement/user-agreement-ledger')
            ->assertRedirect('/procurement/agreements');
    }

    public function test_each_lease_has_a_page_of_its_own()
    {
        $this->seedLeaseAsset([
            'Lease Contract ID' => '301452-007',
            'Lease Contract Name' => 'Devices Leases FY30-31 #4',
            'Ownership Type' => 'Lease to Return',
            'Lease Rent' => '72.03',
            'Lease End Date' => '2030-06-30',
        ], ['serial' => 'HG9FC7K7DJ', 'purchase_date' => '2026-06-01', 'purchase_cost' => 3061.22]);

        $this->actingAs($this->superuser())
            ->get('/procurement/leasing/301452-007')
            ->assertOk()
            ->assertSee('Devices Leases FY30-31 #4')
            ->assertSee('301452-007')
            ->assertSee('HG9FC7K7DJ')
            ->assertSee('$3,061.22')
            ->assertSee(trans('admin/purchase-orders/general.lease_detail_schedule'));

        // An id that is not a lease is a 404, not an empty page.
        $this->actingAs($this->superuser())
            ->get('/procurement/leasing/NOT-A-LEASE')
            ->assertNotFound();

        // The Rent Costs table links each contract to its page.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.rent-costs', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee(route('reports.procurement.lease-detail', '301452-007'), false);
    }

    public function test_the_capital_request_is_one_link_for_finance()
    {
        // A contract ending inside FY2026-27 (Apr 2026 – Mar 2027), with a
        // live device: it appears as a refresh line priced at the
        // replacement estimate (original cost when no catalog mapping).
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-1',
            'Lease Contract Name' => 'Devices Leases FY26-27 #8',
            'Ownership Type' => 'Lease to Return',
            'Lease End Date' => '2026-10-01',
            'Usage' => 'Curriculum',
        ], ['purchase_cost' => 2500.00]);

        $response = $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.capital_request_title'))
            ->assertSee('ECI-CAPREQ-1')
            ->assertSee('$2,500.00')
            ->assertSee(trans('admin/purchase-orders/general.capital_pref_rental'));

        // A different year does not carry this contract.
        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2028-29')
            ->assertOk()
            ->assertDontSee('ECI-CAPREQ-1');

        // The CSV export ships the same rows for the finance workbook.
        $csv = $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27&format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('ECI-CAPREQ-1', $csv->streamedContent());

        // Capital Spend kept its report, one path over.
        $this->actingAs($this->superuser())
            ->get('/procurement/capital-spend')
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_capital'));
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

        // PR #138's ledger overhaul drops the Paid / Remaining money
        // columns — only Contract Value (the type-appropriate cost) is
        // shown now. For upgrades that's top_up_amount.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.user-agreement-ledger'))
            ->assertOk()
            ->assertSee('Carlo Ghioni')
            ->assertSee(trans('admin/purchase-orders/general.user_agreement_stage_value_in_repayment'))
            ->assertSee('$1,200.00');
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
            'agreement_type' => 'purchase',
            'user_id' => $user->id,
            'lifecycle_stage' => 'closed_buyout',
            'buyout_cost' => 800,
            'old_asset_tag' => 'F-OLD-1',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.user-agreement-ledger', ['agreement_type' => 'purchase']))
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
            // The unsigned-agreements count now lives in the Deploying
            // chevron on the pipeline rail.
            ->assertSee(trans('admin/purchase-orders/general.pipeline_agreements_sent', ['count' => 1]));
    }

    /**
     * Seed one asset carrying the given lease fields (keyed by the historical
     * custom-field name). Returns the asset. As of the F2·2 read cutover the
     * lease reports read the native `assets` columns, so this writes those
     * (resolved from the field name via the shim's own map).
     */
    private function seedLeaseAsset(array $fields, array $assetAttrs = []): Asset
    {
        $active = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create(array_merge(['status_id' => $active->id], $assetAttrs));

        $update = [];
        foreach ($fields as $name => $value) {
            $native = Asset::nativeColumnForCustomName($name);
            if ($native) {
                $update[$native] = $value;
            }
        }
        if ($update) {
            Asset::query()->whereKey($asset->id)->update($update);
        }

        return $asset->fresh();
    }

    public function test_disposition_grid_lists_serials_under_a_contract_dropdown()
    {
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20221201',
            'Lease End Date' => '2026-12-31',
        ], ['asset_tag' => 'DISP-1', 'serial' => 'SERIALDISP1']);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.report_disposition_grid'))
            // Contracts are selected via a dropdown now, not a tab strip.
            ->assertSee('disp-contract-select', false)
            ->assertSee('ECI20221201')
            ->assertSee('SERIALDISP1')
            // Provider label reflects the CCA rename, not the retired Macquarie.
            ->assertSee('CCA Financial')
            ->assertDontSee('Macquarie');

        // Embed (dashboard inline) renders the same grid partial with the picker.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid', ['embed' => 1]))
            ->assertOk()
            ->assertSee('disp-contract-select', false)
            ->assertSee('SERIALDISP1');

        // CSV hand-off flattens every contract's serials into one table.
        $csv = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('SERIALDISP1', $csv->streamedContent());
    }

    public function test_disposition_grid_note_endpoint_saves_per_serial()
    {
        $asset = $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20221201',
            'Lease End Date' => '2026-12-31',
        ], ['asset_tag' => 'DISP-2', 'serial' => 'SERIALDISP2']);

        // The disposition itself is read-only (from status); only the note is
        // editable. The note row carries no decision_type.
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.disposition-grid.note'), [
                'asset_id' => $asset->id,
                'contract_reference' => 'ECI20221201',
                'notes' => 'Bought out — kept for the loaner pool.',
            ])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('lease_decisions', [
            'asset_id' => $asset->id,
            'contract_reference' => 'ECI20221201',
            'decision_type' => null,
            'notes' => 'Bought out — kept for the loaner pool.',
        ]);
    }

    public function test_disposition_grid_note_endpoint_clears_per_serial()
    {
        $asset = $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20221201',
        ], ['serial' => 'SERIALDISP3']);

        LeaseDecision::factory()->create([
            'asset_id' => $asset->id,
            'contract_reference' => 'ECI20221201',
            'decision_type' => null,
            'notes' => 'old note',
        ]);

        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.disposition-grid.note'), [
                'asset_id' => $asset->id,
                'contract_reference' => 'ECI20221201',
                'notes' => '',
            ])
            ->assertOk()
            ->assertJson(['cleared' => true]);

        $this->assertDatabaseMissing('lease_decisions', [
            'asset_id' => $asset->id,
            'deleted_at' => null,
        ]);
    }

    public function test_disposition_grid_excludes_fully_returned_leases_and_keeps_active()
    {
        // An active lease (deployable status) shows…
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20990701',
        ], ['serial' => 'ACTIVELEASE1']);

        // …a fully-archived lease (all devices returned) drops off.
        $archived = \App\Models\Statuslabel::factory()->archived()->create();
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20880101',
        ], ['serial' => 'RETURNEDLEASE1', 'status_id' => $archived->id]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid'))
            ->assertOk()
            ->assertSee('ECI20990701')
            ->assertSee('ACTIVELEASE1')
            ->assertDontSee('ECI20880101');
    }

    public function test_disposition_grid_relabels_usage_as_curriculum_and_admin()
    {
        // The Usage field carries the raw automation values (location-assigned
        // ⇒ Shared, person-assigned ⇒ Assigned); finance reads them as the
        // workbook's Curriculum / Admin split.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20240801-1',
            'Usage' => 'Shared',
        ], ['serial' => 'SHAREDSERIAL']);
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20240801-1',
            'Usage' => 'Assigned',
        ], ['serial' => 'ASSIGNEDSERIAL']);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.disposition_use'))
            ->assertSee(trans('admin/purchase-orders/general.use_curriculum'))
            ->assertSee(trans('admin/purchase-orders/general.use_admin'));
    }

    public function test_disposition_grid_csv_orders_buyout_after_decommissioned_and_relabels_use()
    {
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20240801-2',
            'Usage' => 'Shared',
            'Buyout Cost' => '1234',
        ], ['serial' => 'CSVSERIAL']);

        $csv = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid', ['format' => 'csv']));
        $csv->assertOk();

        $content = $csv->streamedContent();
        $header = strtok($content, "\n");

        $decomPos = strpos($header, trans('admin/purchase-orders/general.disposition_decommissioned_date'));
        $buyoutPos = strpos($header, trans('admin/purchase-orders/general.detail_buyout_cost'));
        $usePos = strpos($header, trans('admin/purchase-orders/general.disposition_use'));

        $this->assertNotFalse($buyoutPos);
        $this->assertNotFalse($usePos);
        // Buyout Cost sits immediately right of the Decommissioned Date, before Use.
        $this->assertLessThan($buyoutPos, $decomPos);
        $this->assertLessThan($usePos, $buyoutPos);
        // The finance label, not the raw automation value, lands in the export.
        $this->assertStringContainsString(trans('admin/purchase-orders/general.use_curriculum'), $content);
    }

    public function test_disposition_grid_xlsx_downloads_a_workbook()
    {
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20240801-3',
            'Usage' => 'Shared',
        ], ['serial' => 'XLSXSERIAL']);

        $res = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid', ['format' => 'xlsx']));

        $res->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $res->headers->get('content-type')
        );
        $this->assertStringContainsString(
            'attachment',
            (string) $res->headers->get('content-disposition')
        );
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

    public function test_the_leasing_page_carries_the_charts_and_both_tables()
    {
        // The Leasing page owns the portfolio: the three charts (Annual
        // Rent leading), the lessor breakdown, and the year's Rent Costs.
        $this->actingAs($this->superuser())
            ->get(route('reports.lessor-breakdown'))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.lessor_chart_annual_rent'))
            ->assertSee(trans('admin/purchase-orders/general.lessor_chart_cost'))
            ->assertSee('chart-lessor-ownership')
            ->assertSee(trans('admin/purchase-orders/general.report_lessor_breakdown'))
            ->assertSee(trans('admin/purchase-orders/general.report_rent_costs'));

        $this->actingAs($this->superuser())
            ->get(route('reports.lessor-breakdown', ['format' => 'csv']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        // The page lives at /procurement/leasing; both prior addresses walk
        // there, and the reports hub no longer renders the section.
        $this->actingAs($this->superuser())
            ->get('/procurement/leasing')
            ->assertOk();
        $this->actingAs($this->superuser())
            ->get('/procurement/lessor-breakdown')
            ->assertRedirect('/procurement/leasing');
        $this->actingAs($this->superuser())
            ->get('/reports/lessor-breakdown')
            ->assertRedirect('/procurement/leasing');
        $this->actingAs($this->superuser())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee('chart-lessor-ownership');
    }

    public function test_rent_costs_breaks_the_year_down_per_contract()
    {
        // One contract fully inside the selected year at $100/month of
        // complete per-device rent: twelve months, $1,200 for the year.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-RENT-1',
            'Lease Contract Name' => 'Devices Leases FY26-27 #9',
            'Lease Rent' => '100',
            'Lease End Date' => now()->startOfYear()->addYears(3)->format('Y-m-d'),
        ], ['purchase_date' => now()->subYear()->format('Y-m-d')]);

        $fy = now()->month >= 4 ? now()->year : now()->year - 1;

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.rent-costs', ['fiscal_year' => sprintf('FY%d-%02d', $fy, ($fy + 1) % 100)]))
            ->assertOk()
            ->assertSee('ECI-RENT-1')
            ->assertSee('Devices Leases FY26-27 #9')
            ->assertSee('$1,200.00');
    }

    public function test_procurement_dashboard_leads_with_the_new_report_order_and_drops_lessor_breakdown()
    {
        $content = $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            ->getContent();

        // Reports live in stage tabs now; within the Budgeting stage the
        // sub-tabs keep the agreed order: PO Budget & Spend first, then
        // Capital Spend, then Extension Watch.
        $positions = array_map(
            fn ($anchor) => strpos($content, 'data-pr-report="proc-'.$anchor.'"'),
            ['report_po_budget', 'report_lease_end_schedules', 'report_extension_watch'],
        );
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        // Lessor Breakdown left the procurement list for the reports root.
        $this->assertStringNotContainsString('proc-report_lessor_breakdown', $content);

        // The register dropped the ARO initialism.
        $this->assertSame('Buyout Register', trans('admin/purchase-orders/general.report_aro_register'));
    }

    public function test_reports_read_provider_from_the_lessor_field()
    {
        $lessor = Supplier::factory()->create(['name' => 'Acme Leasing Co']);
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20240801',
        ], ['serial' => 'LESSORFK1', 'lessor_id' => $lessor->id]);

        // The disposition grid reads the provider from the asset's lessor FK,
        // not the ECI->CCA prefix fallback.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid'))
            ->assertOk()
            ->assertSee('Acme Leasing Co');
    }

    public function test_lessor_breakdown_uses_cca_financial_and_ignores_fy_scope()
    {
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20221201',
        ], ['asset_tag' => 'LESSOR-1', 'purchase_date' => '2022-12-01']);

        // The breakdown is a global snapshot: whatever FY scope the reader
        // arrives with, the portfolio still shows in full.
        $this->actingAs($this->superuser())
            ->get(route('reports.lessor-breakdown', ['fiscal_year' => 'FY2099-00']))
            ->assertOk()
            ->assertSee('CCA Financial')
            ->assertDontSee('Macquarie');
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
            // Pending approvals surface in the Reconciling chevron; lease
            // decisions live on the returns lane of the pipeline board.
            ->assertSee(trans('admin/purchase-orders/general.pipeline_note_invoices_pending', ['count' => 1]))
            ->assertSee(trans('admin/purchase-orders/general.returns_card_title'));
    }

    public function test_leases_financial_report_rolls_up_warranty_costs()
    {
        $active = Statuslabel::factory()->rtd()->create();

        $asset = Asset::factory()->create([
            'asset_tag' => 'LEASE-FIN-1',
            'status_id' => $active->id,
            'purchase_cost' => 1000.00,
            // The CDW order lives on the asset itself (source of truth); the
            // report reads it from here, falling back to the linked order.
            'order_number' => 'PMCN-FIN-1',
        ]);
        Asset::query()->whereKey($asset->id)->update(['lease_contract_id' => '301452-003']);

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
            ->get(route('reports.procurement.leases-financial', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('PMCN-FIN-1')
            ->assertSee('$155.70')
            ->assertSee('$1,155.70');
    }

    public function test_po_budget_committed_is_sourced_from_assets()
    {
        $po = PurchaseOrder::factory()->create([
            'po_number' => 'P0099001', 'budget' => 10000, 'fiscal_year' => 'FY2025-26',
        ]);

        // Two devices charged to the PO via the asset native PO Number column,
        // bought inside FY2025-26: committed = (1000 + 150 warranty) + (2000 + 0).
        foreach ([[1000.00, '150.00'], [2000.00, '0.00']] as [$cost, $warranty]) {
            $asset = Asset::factory()->create(['purchase_cost' => $cost, 'purchase_date' => '2025-06-01']);
            Asset::query()->whereKey($asset->id)->update([
                'po_number' => 'P0099001',
                'warranty_soft_cost' => $warranty,
            ]);
        }

        // A device on the same PO but bought in a different FY must not count.
        $other = Asset::factory()->create(['purchase_cost' => 5000.00, 'purchase_date' => '2026-06-01']);
        Asset::query()->whereKey($other->id)->update(['po_number' => 'P0099001']);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.po-budget', ['fiscal_year' => 'FY2025-26']))
            ->assertOk()
            ->assertSee('P0099001')
            ->assertSee('$3,150.00')   // committed from assets (equipment + warranty)
            ->assertDontSee('$8,150.00'); // the FY2026-27 device is excluded
    }

    public function test_invoiced_tile_surfaces_po_less_invoice_by_invoice_date()
    {
        // A budgeted PO in each FY so both years are selectable on the
        // dashboard (the invoices themselves carry no PO/FY). Budgets are
        // chosen not to collide with the invoice totals asserted below.
        PurchaseOrder::factory()->create(['po_number' => 'PO-SEL-27', 'fiscal_year' => 'FY2026-27', 'budget' => 100.00]);
        PurchaseOrder::factory()->create(['po_number' => 'PO-SEL-26', 'fiscal_year' => 'FY2025-26', 'budget' => 200.00]);

        // Two CDW-ingested orders with no PO link and no stamped fiscal_year
        // (the AJ7FG1T pattern), billed by invoices dated in different FYs.
        // Each must surface via its own invoice_date, not vanish for want of
        // a PO, and must be scoped to the right year.
        foreach ([
            ['CDW-FY27', 'AJ7FG1T', '2026-06-11', 14296.50],
            ['CDW-FY26', 'AJ6XX99', '2025-06-11', 5555.55],
        ] as [$orderNo, $invNo, $invDate, $total]) {
            $order = Order::factory()->create([
                'order_number' => $orderNo,
                'purchase_order_id' => null,
                'fiscal_year' => null,
            ]);
            OrderInvoice::factory()->create([
                'order_id' => $order->id,
                'purchase_order_id' => null,
                'invoice_number' => $invNo,
                'invoice_date' => $invDate,
                'total' => $total,
                'approval_status' => 'pending',
            ]);
        }

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee('$14,296.50')    // FY26-27 invoice on the Invoiced tile
            ->assertDontSee('$5,555.55'); // the FY25-26 invoice is scoped out

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2025-26']))
            ->assertOk()
            ->assertSee('$5,555.55')
            ->assertDontSee('$14,296.50');
    }

    public function test_committed_counts_orphan_pos_with_no_ledger_row()
    {
        // A university PO the fleet was received against — but no row was ever
        // booked in the purchase_orders ledger (the P0025747 / P0025807 case).
        $asset = Asset::factory()->create(['purchase_cost' => 2500.00, 'purchase_date' => '2025-06-01']);
        Asset::query()->whereKey($asset->id)->update(['po_number' => 'P0025747']);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2025-26']))
            ->assertOk()
            // The orphan PO and its spend surface in Committed even with no
            // purchase_orders ledger row.
            ->assertSee('P0025747')
            ->assertSee('$2,500.00');
    }

    public function test_sub_report_filters_by_order_fiscal_year()
    {
        // One blanket PO carrying orders booked in two fiscal years — the
        // 007/008-on-P0025420 pattern. The FY filter has to attribute each
        // invoice by its booking order's FY, not the parent PO's.
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-BLANKET', 'fiscal_year' => 'FY2025-26']);

        $order25 = Order::factory()->create([
            'order_number' => 'ORD-FY25',
            'purchase_order_id' => $po->id,
            'fiscal_year' => 'FY2025-26',
        ]);
        $order26 = Order::factory()->create([
            'order_number' => 'ORD-FY26',
            'purchase_order_id' => $po->id,
            'fiscal_year' => 'FY2026-27',
        ]);

        OrderInvoice::factory()->create([
            'order_id' => $order25->id,
            'purchase_order_id' => $po->id,
            'invoice_number' => 'INV-FY25',
        ]);
        OrderInvoice::factory()->create([
            'order_id' => $order26->id,
            'purchase_order_id' => $po->id,
            'invoice_number' => 'INV-FY26',
        ]);

        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->get(route('reports.procurement.invoices', ['fiscal_year' => 'FY2025-26']))
            ->assertOk()
            ->assertSee('INV-FY25')
            ->assertDontSee('INV-FY26');

        $this->actingAs($superuser)
            ->get(route('reports.procurement.invoices', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee('INV-FY26')
            ->assertDontSee('INV-FY25');

        // ?fiscal_year=all opts out and shows both years.
        $this->actingAs($superuser)
            ->get(route('reports.procurement.invoices', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('INV-FY25')
            ->assertSee('INV-FY26');
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

    public function test_pipeline_budget_auto_includes_lease_end_preapproval()
    {
        // A schedule ending inside FY2026-27 (Apr–Mar): its original value
        // is pre-approved and must join the approved budget automatically.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20220901',
            'Lease End Date' => '2026-09-01',
        ], ['serial' => 'PREAPPROVE1', 'purchase_cost' => 1500.50]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertViewHas('totalBudget', fn ($budget) => abs($budget - 1500.50) < 0.01);

        // A posted lease_preapproval allocation overrides the live figure.
        \App\Models\BudgetAllocation::create([
            'fiscal_year' => 'FY2026-27',
            'amount' => 999.00,
            'source' => 'lease_preapproval',
            'description' => 'Finance-adjusted pre-approval',
            'created_by' => $this->superuser()->id,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertViewHas('totalBudget', fn ($budget) => abs($budget - 999.00) < 0.01);
    }

    public function test_ministry_capital_allocation_posts_and_joins_the_approved_budget()
    {
        // The store endpoint accepts the new source…
        $this->actingAs($this->superuser())
            ->post(route('budget_allocations.store'), [
                'fiscal_year' => 'FY2026-27',
                'amount' => 107783.40,
                'source' => 'ministry_capital',
                'description' => 'Ministry capital — MacBook Air cart order (CDW quote)',
            ])
            ->assertRedirect(route('reports.procurement', ['fiscal_year' => 'FY2026-27']));

        $this->assertDatabaseHas('budget_allocations', [
            'fiscal_year' => 'FY2026-27',
            'source' => 'ministry_capital',
        ]);

        // …and the injection joins Approved Budget like any other allocation,
        // so the externally funded order's committed spend nets out.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertViewHas('totalBudget', fn ($budget) => abs($budget - 107783.40) < 0.01)
            ->assertSee(trans('admin/budget-allocations/general.source_ministry_capital'));
    }

    public function test_lease_plan_note_creates_a_note_only_row_that_stays_out_of_decisions()
    {
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20230701',
            'Lease End Date' => '2027-06-30',
        ], ['serial' => 'PLANNOTE1']);

        // First edit creates the note-only row (no asset, no decision type)…
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.note'), [
                'model' => 'lease_plan_note',
                'contract_reference' => 'ECI20230701',
                'notes' => 'Budget redirected to the Faculty Laptop program.',
            ])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('lease_decisions', [
            'contract_reference' => 'ECI20230701',
            'asset_id' => null,
            'decision_type' => null,
            'notes' => 'Budget redirected to the Faculty Laptop program.',
        ]);

        // …a second edit updates the same row instead of stacking new ones.
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.note'), [
                'model' => 'lease_plan_note',
                'contract_reference' => 'ECI20230701',
                'notes' => 'Revised plan.',
            ])
            ->assertOk();
        $this->assertEquals(1, LeaseDecision::where('contract_reference', 'ECI20230701')->count());

        // The note renders on the schedule row, but the note-only row never
        // shows up as a logged decision (the badge stays "Refresh").
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.lease-end-schedules', ['fiscal_year' => 'FY2027-28']))
            ->assertOk()
            ->assertSee('Revised plan.')
            ->assertSee(trans('admin/purchase-orders/general.lease_end_refresh_planned'));

        // And the Lease Decisions report skips it.
        $report = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.lease-decisions'));
        $report->assertOk()->assertDontSee('Revised plan.');
    }

    public function test_prefixed_cca_contract_ids_stay_recognised()
    {
        // The 4130- lessor-account prefix (2026-08 rename) must keep CCA
        // schedules inside every lease rollup — the validity check once
        // required a bare ECI prefix, which silently dropped them all.
        $this->seedLeaseAsset([
            'Lease Contract ID' => '4130-ECI20240801-1',
            'Lease End Date' => '2028-08-01',
        ], ['serial' => 'PREFIXED1']);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid'))
            ->assertOk()
            ->assertSee('4130-ECI20240801-1')
            ->assertSee('PREFIXED1');
    }

    public function test_disposition_grid_deep_links_a_contract_and_scopes_downloads()
    {
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20200101',
            'Lease End Date' => '2026-01-31',
        ], ['serial' => 'FIRSTLEASE1']);
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20300101',
            'Lease End Date' => '2027-01-31',
        ], ['serial' => 'SECONDLEASE1']);

        // ?contract= preselects that lease's pane (the first contract would
        // otherwise win) and stamps the scoped download links.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid', ['contract' => 'ECI20300101']))
            ->assertOk()
            ->assertSee('data-contract="ECI20300101" selected', false)
            ->assertSee('contract=ECI20300101', false);

        // A substring still resolves — links minted before a schedule id
        // rename (e.g. the 4130- lessor prefix) keep working.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid', ['contract' => '20300101']))
            ->assertOk()
            ->assertSee('data-contract="ECI20300101" selected', false);

        // The scoped CSV carries only the selected contract's serials.
        $csv = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid', ['format' => 'csv', 'contract' => 'ECI20300101']));
        $csv->assertOk();
        $this->assertStringContainsString('SECONDLEASE1', $csv->streamedContent());
        $this->assertStringNotContainsString('FIRSTLEASE1', $csv->streamedContent());

        // An unscoped export still flattens every contract.
        $all = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.disposition-grid', ['format' => 'csv']));
        $this->assertStringContainsString('FIRSTLEASE1', $all->streamedContent());
        $this->assertStringContainsString('SECONDLEASE1', $all->streamedContent());
    }

    public function test_disposition_grid_update_endpoint_bulk_edits_lifecycle_fields()
    {
        $first = $this->seedLeaseAsset(['Lease Contract ID' => 'ECI20221201'], ['serial' => 'BULKEDIT1']);
        $second = $this->seedLeaseAsset(['Lease Contract ID' => 'ECI20221201'], ['serial' => 'BULKEDIT2']);
        $untouched = $this->seedLeaseAsset(['Lease Contract ID' => 'ECI20221201'], ['serial' => 'BULKEDIT3']);
        $archived = Statuslabel::factory()->archived()->create();

        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.disposition-grid.update'), [
                'asset_ids' => [$first->id, $second->id],
                'status_id' => $archived->id,
                'decommission_date' => '2026-08-01',
                'buyout_cost' => '150.25',
            ])
            ->assertOk()
            ->assertJson(['status' => 'success', 'updated' => 2]);

        foreach ([$first, $second] as $asset) {
            $fresh = $asset->fresh();
            $this->assertEquals($archived->id, $fresh->status_id);
            $this->assertEquals('2026-08-01', (string) $fresh->decommission_date);
            $this->assertEquals(150.25, (float) $fresh->buyout_cost);
        }

        // A device outside the selection is untouched…
        $this->assertNotEquals($archived->id, $untouched->fresh()->status_id);

        // …an omitted field stays put, and an emptied field clears.
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.disposition-grid.update'), [
                'asset_ids' => [$first->id],
                'decommission_date' => '',
                'buyout_cost' => '',
            ])
            ->assertOk();

        $fresh = $first->fresh();
        $this->assertEquals($archived->id, $fresh->status_id);
        $this->assertEmpty($fresh->decommission_date);
        $this->assertEmpty($fresh->buyout_cost);
    }

    public function test_disposition_grid_update_endpoint_requires_asset_update_permission()
    {
        $asset = $this->seedLeaseAsset(['Lease Contract ID' => 'ECI20221201'], ['serial' => 'NOEDIT1']);

        $this->actingAs(User::factory()->create())
            ->post(route('reports.procurement.disposition-grid.update'), [
                'asset_ids' => [$asset->id],
                'decommission_date' => '2026-08-01',
            ])
            ->assertForbidden();
    }
}
