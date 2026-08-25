<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\BudgetAllocation;
use App\Models\CapitalRequestLine;
use App\Models\CatalogItem;
use App\Models\DeploymentItem;
use App\Models\DeploymentWave;
use App\Models\LeaseDecision;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAgreement;
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
            ->assertSee('procPoChart');
    }

    public function test_the_tile_row_follows_the_fiscal_year_scope()
    {
        PurchaseOrder::factory()->create(['po_number' => 'PO-SCOPE', 'fiscal_year' => 'FY2026-27', 'budget' => 25000]);
        $superuser = $this->superuser();

        // Inside one year: utilisation against that year's pot, and the
        // returns the year is chasing.
        $this->actingAs($superuser)
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            // The canvas, not the script that looks for it by id.
            ->assertSee('<canvas id="procUtilChart">', false)
            ->assertSee('>'.trans('admin/purchase-orders/general.returns_card_title').'<', false)
            ->assertDontSee('<canvas id="procFyChart">', false);

        // Across every year: the year-on-year comparison, and neither of the
        // two that only mean something inside a cycle.
        $this->actingAs($superuser)
            ->get(route('reports.procurement', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('<canvas id="procFyChart">', false)
            ->assertDontSee('<canvas id="procUtilChart">', false)
            // The card's heading, not the word wherever it appears — a
            // script comment on the same page mentions it too.
            ->assertDontSee('>'.trans('admin/purchase-orders/general.returns_card_title').'<', false);
    }

    public function test_the_monthly_invoiced_chart_is_gone()
    {
        // Four points of invoice timing told nobody anything they act on.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertDontSee('procMonthlyChart');
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

        // One forecast page now: the procurement address redirects into
        // the deployments planning hub, where the page and CSV both live.
        $this->actingAs($superuser)
            ->get(route('reports.procurement.forecast'))
            ->assertRedirect(route('deployments.planning'));

        $this->actingAs($superuser)
            ->get(route('deployments.planning'))
            ->assertOk()
            ->assertSee('FORECAST-1');

        $csv = $this->actingAs($superuser)
            ->get(route('deployments.planning', ['format' => 'csv']));
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
            ->get(route('deployments.planning'))
            ->assertOk()
            ->assertSee('js-lightbox')
            ->assertSee(route('hardware.show', $asset->id), false)
            // …and the layout carries the lightbox host + framed hook.
            ->assertSee('id="app-lightbox"', false)
            ->assertSee('html.framed', false);

        // The links map is render-time only — exports carry clean cells.
        $csv = $this->actingAs($superuser)
            ->get(route('deployments.planning', ['format' => 'csv']));
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
        $lessor = Supplier::factory()->create(['name' => 'CSI Leasing Canada']);

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

    public function test_open_requisitions_surface_on_the_capital_request()
    {
        $requisition = Requisition::create([
            'title' => 'Foundation Mobile MacBook Labs',
            'status' => 'submitted',
            'requisition_number' => '0017859',
            'fiscal_year' => 'FY2026-27',
        ]);

        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.capital_reqs_title'))
            ->assertSee('REQM 0017859')
            ->assertSee(route('requisitions.show', $requisition->id), false);

        // Once a PO exists it moves to the issued list and leaves this one.
        $po = PurchaseOrder::factory()->create(['po_number' => 'P0026099', 'fiscal_year' => 'FY2026-27']);
        $requisition->forceFill(['purchase_order_id' => $po->id])->save();

        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertDontSee(trans('admin/purchase-orders/general.capital_reqs_title'))
            ->assertSee('P0026099');
    }

    public function test_the_envelope_is_the_request_and_kept_contracts_feed_it()
    {
        // A refreshing contract and a kept lease-to-own, both ending in the
        // year. The pre-approved envelope is the FULL original value of
        // both — the kept contract contributes its budget while asking for
        // no devices, which is how that money gets redistributed.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-REF',
            'Ownership Type' => 'Lease to Return',
            'Lease End Date' => '2026-10-01',
        ], ['purchase_cost' => 2500.00]);
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-KEPT',
            'Ownership Type' => 'Lease to Own',
            'Lease End Date' => '2026-12-31',
        ], ['purchase_cost' => 3200.00]);
        LeaseDecision::factory()->create([
            'contract_reference' => 'ECI-CAPREQ-KEPT',
            'decision_type' => 'buyout',
            'status' => 'approved',
        ]);

        // The envelope table carries both contracts at full value; the kept
        // contract appears there and ONLY there — never as a request line —
        // which is how its budget stays in the envelope for redistribution.
        $content = $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertSee('$5,700.00')
            ->assertSee(trans('admin/purchase-orders/general.capital_envelope_title'))
            ->assertSee(trans('admin/purchase-orders/general.lease_end_retained'))
            ->getContent();
        $this->assertSame(1, substr_count($content, '>ECI-CAPREQ-KEPT</a>'));
        $this->assertSame(2, substr_count($content, '>ECI-CAPREQ-REF</a>'));

        // The draft carries only the refresh distribution.
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.capital-request.draft'), ['fiscal_year' => 'FY2026-27']);
        $requisition = Requisition::latest('id')->first();
        $this->assertNotNull($requisition);
        $this->assertSame(1, $requisition->items()->count());
    }

    public function test_the_request_reads_the_wave_plan_and_populates_the_paper_back()
    {
        // A device on an ending contract, already planned into a wave with
        // a DIFFERENT replacement model: the wave's plan wins over the
        // like-for-like forecast, and the wave rides on the line.
        $asset = $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-WAVE',
            'Ownership Type' => 'Lease to Return',
            'Lease End Date' => '2026-10-01',
        ], ['purchase_cost' => 2000.00]);

        $planned = AssetModel::factory()->create(['name' => 'MacBook Air 13 M5']);
        $wave = DeploymentWave::create([
            'name' => 'FY26-27 Faculty Refresh', 'slug' => 'fy2627-faculty-'.uniqid(), 'fiscal_year' => 'FY2026-27',
        ]);
        DeploymentItem::create([
            'wave_id' => $wave->id, 'replaces_asset_id' => $asset->id, 'model_id' => $planned->id,
        ]);

        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertSee('MacBook Air 13 M5')
            ->assertSee('FY26-27 Faculty Refresh');

        // Draft it; the REQM column then names the requisition the line
        // landed on, and once finance issues a PO it appears too.
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.capital-request.draft'), ['fiscal_year' => 'FY2026-27']);
        $requisition = Requisition::latest('id')->first();
        $requisition->forceFill(['requisition_number' => '0017999'])->save();

        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertSee('REQM 0017999');

        $po = PurchaseOrder::factory()->create(['po_number' => 'P0026150', 'fiscal_year' => 'FY2026-27']);
        $requisition->forceFill(['purchase_order_id' => $po->id])->save();

        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertSee('P0026150');
    }

    public function test_the_forecast_carries_no_capital_money()
    {
        // The forecast is the device-planning surface: no envelope, no
        // requested total, no per-device dollars. The capital money lives
        // at /capital and in the PO Builder.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-STRIP-1',
            'Ownership Type' => 'Lease to Return',
            'Lease End Date' => '2026-10-01',
        ], ['purchase_cost' => 2500.00]);

        $this->actingAs($this->superuser())
            ->get(route('deployments.planning', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertDontSee(trans('admin/deployments/general.forecast_funds_chip', ['amount' => '2,500.00']))
            ->assertDontSee('2,500.00');
    }

    public function test_a_self_contained_requisition_never_attaches_to_request_lines()
    {
        // The Foundation labs case: a stand-alone REQM buying the same
        // product a refresh line forecasts. Same catalog item, zero
        // connection — the request's paper is only what was drafted FROM
        // the request.
        $model = AssetModel::factory()->create(['name' => 'MacBook Air 13 M5']);
        $catalog = CatalogItem::create([
            'name' => 'MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'family' => 'MacBook Air', 'category' => 'Laptops',
            'product_type' => 'standard', 'price_type' => 'estimate', 'estimated_cost' => 2100.00,
        ]);
        $model->forceFill(['refresh_catalog_item_id' => $catalog->id])->save();

        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-SELF',
            'Ownership Type' => 'Lease to Return',
            'Lease End Date' => '2026-10-01',
        ], ['model_id' => $model->id]);

        // The self-contained requisition: same product, own purpose, PO
        // already issued. fiscal_year matches; capital_request_fy is null.
        $foreign = Requisition::create([
            'title' => 'Foundation Mobile MacBook Labs',
            'status' => 'ordered',
            'requisition_number' => '0017859',
            'fiscal_year' => 'FY2026-27',
        ]);
        RequisitionItem::create([
            'requisition_id' => $foreign->id,
            'catalog_item_id' => $catalog->id,
            'description' => 'MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'quantity' => 42, 'unit_of_measure' => 'EA', 'unit_cost' => 2150.48,
            'pst_applicable' => false, 'sort_order' => 0,
        ]);

        // No attach: the line shows no REQM, and no group forms.
        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertDontSee('REQM 0017859')
            ->assertDontSee('class="capital-group-head"', false);

        // Drafting FROM the request creates lineage, and only then does a
        // REQM group appear.
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.capital-request.draft'), ['fiscal_year' => 'FY2026-27']);
        $draft = Requisition::latest('id')->first();
        $this->assertSame('FY2026-27', $draft->capital_request_fy);
        $draft->forceFill(['requisition_number' => '0018000'])->save();

        // The paper replaces the derivation: flat requisition lines, no
        // group scaffolding, and the foreign requisition still nowhere.
        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertSee('REQM 0018000')
            ->assertDontSee('class="capital-group-head"', false)
            ->assertDontSee('REQM 0017859');
    }

    /**
     * The approval read: once the request has been drafted, the table IS
     * the requisition — same lines, same quantities, same total as the PO
     * builder, with the derived device lines gone and the draft button
     * replaced by the door into the builder.
     */
    public function test_a_drafted_request_reads_exactly_as_its_requisition()
    {
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-MATCH',
            'Ownership Type' => 'Lease to Return',
            'Lease End Date' => '2026-10-01',
        ], ['purchase_cost' => 1234.56]);

        $requisition = \App\Models\Requisition::create([
            'title' => 'Devices Capital Request FY2026-27',
            'status' => 'draft',
            'fiscal_year' => 'FY2026-27',
            'capital_request_fy' => 'FY2026-27',
        ]);
        \App\Models\RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'description' => 'MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'quantity' => 32, 'unit_of_measure' => 'EA', 'unit_cost' => 2100,
            'pst_applicable' => false, 'sort_order' => 0,
        ]);
        \App\Models\RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'description' => 'Mac mini | M4 Pro | 48GB | 2TB',
            'quantity' => 6, 'unit_of_measure' => 'EA', 'unit_cost' => 4500,
            'pst_applicable' => false, 'sort_order' => 1,
        ]);

        $content = $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            // The requisition's lines and total, to the cent.
            ->assertSee('MacBook Air | 13&quot; | M5 | 16GB | 1TB | Silver', false)
            ->assertSee('Mac mini | M4 Pro | 48GB | 2TB')
            ->assertSee('$94,200.00')
            // The draft button is gone; the requisition it made replaces it.
            ->assertDontSee(trans('admin/purchase-orders/general.capital_draft_button'))
            ->assertSee(route('requisitions.show', $requisition->id), false)
            ->getContent();

        // The derived device line is gone: the contract appears exactly
        // once — in the envelope table below, whose story is untouched by
        // how the ask is rendered.
        $this->assertSame(1, substr_count($content, '>ECI-CAPREQ-MATCH</a>'));

        // Drafting again is refused — the paper already exists.
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.capital-request.draft'), ['fiscal_year' => 'FY2026-27'])
            ->assertSessionHas('error');
        $this->assertSame(1, \App\Models\Requisition::where('capital_request_fy', 'FY2026-27')->count());
    }

    public function test_a_devices_request_year_follows_the_decision_not_the_paper()
    {
        // The faculty case: a 5-year lease ending in FY2027-28, refreshed
        // at year 4 by a FY2026-27 wave. The wave is the decision, so the
        // line belongs to FY2026-27's request — not the year the paper
        // expires.
        $waved = $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-EARLY',
            'Ownership Type' => 'Lease to Own',
            'Lease End Date' => '2027-08-01',
        ], ['purchase_cost' => 2100.00]);
        $wave = DeploymentWave::create([
            'name' => 'FY26-27 Faculty Wave', 'slug' => 'fy2627-early-'.uniqid(), 'fiscal_year' => 'FY2026-27',
        ]);
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $waved->id]);

        // Same contract, no wave, but an End of Life WE set earlier than
        // the lease end: the forecast's operative date wins. (Stamped after
        // create — the factory's afterMaking overwrites asset_eol_date.)
        $early = $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-EARLY',
            'Ownership Type' => 'Lease to Own',
            'Lease End Date' => '2027-08-01',
        ], ['purchase_cost' => 1900.00]);
        Asset::query()->whereKey($early->id)->update(['asset_eol_date' => '2026-10-01']);

        // And one with nothing sharper decided: lease end stands. The EOL
        // has to be cleared explicitly — the asset factory stamps a RANDOM
        // one (purchase date plus 0-60 months), and whenever that landed
        // inside FY2026-27 it beat the lease end as the operative date and
        // dragged this device into the earlier year. That is what made this
        // test fail intermittently in CI on unrelated branches.
        $undecided = $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-EARLY',
            'Ownership Type' => 'Lease to Own',
            'Lease End Date' => '2027-08-01',
        ], ['purchase_cost' => 1700.00]);
        Asset::query()->whereKey($undecided->id)->update(['asset_eol_date' => null]);

        // FY2026-27 carries the wave device and the early-EOL device…
        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertSee('$2,100.00')
            ->assertSee('$1,900.00')
            ->assertDontSee('$1,700.00');

        // …and FY2027-28 keeps only the undecided one.
        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2027-28')
            ->assertOk()
            ->assertSee('$1,700.00')
            ->assertDontSee('$2,100.00')
            ->assertDontSee('$1,900.00');
    }

    public function test_new_asks_are_entered_by_hand_and_join_the_draft()
    {
        // The new asks are decisions, typed in — never derived from orders.
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.capital-request.lines.store'), [
                'fiscal_year' => 'FY2026-27',
                'need' => 'New Ask - Research TechServ',
                'description' => 'Lenovo ThinkStation P620 64GB 2TB',
                'quantity' => 6,
                'unit_cost' => 4885.00,
            ])
            ->assertRedirect(route('reports.procurement.capital-request', ['fiscal_year' => 'FY2026-27']));

        $this->actingAs($this->superuser())
            ->get('/procurement/capital?fiscal_year=FY2026-27')
            ->assertOk()
            ->assertSee('New Ask - Research TechServ')
            ->assertSee('$29,310.00');

        // It rides into the PO draft as a free-form line.
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.capital-request.draft'), ['fiscal_year' => 'FY2026-27']);
        $requisition = Requisition::latest('id')->first();
        $this->assertNotNull($requisition);
        $this->assertSame(6, (int) $requisition->items()->first()->quantity);

        // And deletes cleanly.
        $line = CapitalRequestLine::first();
        $this->actingAs($this->superuser())
            ->delete(route('reports.procurement.capital-request.lines.destroy', $line))
            ->assertRedirect(route('reports.procurement.capital-request', ['fiscal_year' => 'FY2026-27']));
        $this->assertSame(0, CapitalRequestLine::count());
    }

    public function test_the_capital_request_becomes_a_builder_draft()
    {
        $model = AssetModel::factory()->create(['name' => 'MacBook Air 13']);
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-2',
            'Ownership Type' => 'Lease to Return',
            'Lease End Date' => '2026-11-01',
        ], ['purchase_cost' => 1800.00, 'model_id' => $model->id]);
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI-CAPREQ-2',
            'Ownership Type' => 'Lease to Return',
            'Lease End Date' => '2026-11-01',
        ], ['purchase_cost' => 1800.00, 'model_id' => $model->id]);

        $response = $this->actingAs($this->superuser())
            ->post(route('reports.procurement.capital-request.draft'), ['fiscal_year' => 'FY2026-27']);

        $requisition = Requisition::latest('id')->first();
        $this->assertNotNull($requisition);
        $response->assertRedirect(route('purchase-orders.builder', ['requisition' => $requisition->id]));

        // Two identical devices on one contract become one two-unit line,
        // priced at the replacement estimate.
        $this->assertSame('draft', $requisition->status);
        $this->assertSame('FY2026-27', $requisition->fiscal_year);
        $line = $requisition->items()->first();
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame(1800.00, (float) $line->unit_cost);
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

        $content = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.po-drilldown'))
            ->assertOk()
            ->assertSee('PO-DRILL-1')
            ->assertSee('PMCN-DRILL-1')
            ->assertSee('INV-DRILL-1')
            ->getContent();

        // The PO is the row and its invoices nest beneath it, the same shape
        // Extension Watch and Asset Lease Detail use. It used to be a flat
        // run of invoice rows with a tinted subtotal marking each boundary,
        // which meant repeating the PO number down a column.
        $this->assertStringContainsString('rpt-child-table', $content);
        $this->assertSame(1, substr_count($content, '>PO-DRILL-1<'));
        $this->assertStringNotContainsString('PO-DRILL-1 '.trans('admin/orders/general.total'), $content);
    }

    public function test_a_po_row_shows_that_something_inside_it_is_off()
    {
        // Folding the detail must not fold the signal: a variance lives on
        // an invoice, but the reader scanning collapsed POs has to see which
        // one is worth opening.
        $po = PurchaseOrder::factory()->create(['po_number' => 'PO-DRILL-VAR', 'budget' => 10000]);
        $order = Order::factory()->create([
            'order_number' => 'PMCN-DRILL-VAR',
            'purchase_order_id' => $po->id,
        ]);
        $invoice = OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-DRILL-VAR',
            'subtotal' => 999.99,
        ]);

        $content = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.po-drilldown'))
            ->assertOk()
            ->getContent();

        // The invoice bills 999.99 against no line items at all, so the whole
        // subtotal is variance and both rows carry the warning.
        $this->assertNotEquals(0.0, round($invoice->variance(), 2));
        // Twice: once on the invoice row that is off, once on the PO row
        // above it that has to say so while collapsed.
        $this->assertGreaterThanOrEqual(2, substr_count($content, 'class="danger"'));
    }

    public function test_faculty_ledger_scopes_by_programme_cycle_not_row_creation_date()
    {
        // Every agreement on prod was written by one backfill, so created_at
        // dates the import and the year filter matched all 70 — the FY2026-27
        // ledger listed devices bought as far back as 2021.
        $member = User::factory()->create();

        // In this year's refresh wave, via the device being replaced.
        $wave = DeploymentWave::create([
            'name' => 'Faculty refresh FY2026-27',
            'slug' => 'faculty-refresh-'.uniqid(),
            'fiscal_year' => 'FY2026-27',
        ]);
        $replaced = Asset::factory()->create(['purchase_date' => '2022-08-10']);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $replaced->id,
        ]);
        // A purchase — the buyout of the outgoing device — is the agreement
        // type the replacing wave legitimately dates. (A pickup on the
        // replaced device belongs to the year the device was issued; that
        // side no longer drags it into the new cycle.)
        $thisCycle = UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $replaced->id,
            'agreement_type' => 'purchase',
            'lifecycle_stage' => 'quoted',
        ]);

        // An older agreement for a device bought two years ago, in no wave.
        $oldAsset = Asset::factory()->create(['purchase_date' => '2024-06-01']);
        $oldCycle = UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $oldAsset->id,
            'agreement_type' => 'pickup',
            'lifecycle_stage' => 'quoted',
        ]);

        $current = UserAgreement::forProgramFiscalYear('FY2026-27')->pluck('id');
        $this->assertTrue($current->contains($thisCycle->id), 'wave member should be in the current year');
        $this->assertFalse($current->contains($oldCycle->id), 'a 2024 device should not be');

        // The old one lands in the year its device was actually bought, even
        // though the row was written this year.
        $earlier = UserAgreement::forProgramFiscalYear('FY2024-25')->pluck('id');
        $this->assertTrue($earlier->contains($oldCycle->id));
        $this->assertFalse($earlier->contains($thisCycle->id));

        // "all" still means all.
        $this->assertEqualsCanonicalizing(
            [$thisCycle->id, $oldCycle->id],
            UserAgreement::forProgramFiscalYear('all')->pluck('id')->all()
        );
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

    public function test_agreements_stay_off_the_rail_but_the_indicator_returned()
    {
        // An agreement is paperwork attached to a laptop, not a separate
        // thing arriving — the cards that double-counted staged devices
        // stay gone. What returned, by request, is a single indicator:
        // an agreement count (not devices) linking to the ledger where
        // the rows are worked.
        $user = User::factory()->create();
        UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'agreement_sent',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            ->assertDontSee('agreements in flight')
            ->assertSee(trans('admin/purchase-orders/general.pipeline_agreements_sent', ['count' => 1]))
            ->assertSee(route('reports.procurement.user-agreement-ledger', ['stage' => 'agreement_sent']), false);
    }

    public function test_the_ledger_dashes_a_zero_rather_than_printing_it()
    {
        // A free pickup costs nothing, so "$0.00" is not a figure anyone
        // reads — it is a column of noise to scan past before the handful
        // of real amounts show themselves.
        $user = User::factory()->create(['first_name' => 'Free', 'last_name' => 'Pickup']);
        UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.user-agreement-ledger', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('Free Pickup')
            // The row's cells, not the grand total in the footer — a total
            // of zero is a real sum and says so.
            ->assertDontSee('<td>$0.00</td>', false);
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

    public function test_asset_lease_detail_groups_devices_under_their_contract()
    {
        // Two devices on one schedule, one on another. Flat, this report
        // repeated the contract id down a column and never totalled it;
        // grouped, the contract is the row and its devices nest beneath.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20240501',
            'Lease End Date' => '2028-05-01',
        ], ['asset_tag' => 'ALD-1', 'serial' => 'ALDSERIAL1', 'purchase_cost' => 1000]);
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20240501',
            'Lease End Date' => '2028-05-01',
        ], ['asset_tag' => 'ALD-2', 'serial' => 'ALDSERIAL2', 'purchase_cost' => 1500]);
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20240601',
            'Lease End Date' => '2028-06-01',
        ], ['asset_tag' => 'ALD-3', 'serial' => 'ALDSERIAL3', 'purchase_cost' => 700]);

        $content = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.asset-lease-detail', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('ECI20240501')
            ->assertSee('ECI20240601')
            // Devices still appear — in the nested table, not as top rows.
            ->assertSee('ALDSERIAL1')
            ->assertSee('ALDSERIAL2')
            ->getContent();

        // One parent row per contract, each carrying a child table.
        $this->assertSame(1, substr_count($content, '>ECI20240501<'));
        $this->assertStringContainsString('rpt-child-table', $content);

        // The contract row totals its devices rather than making the reader
        // add them up: 1000 + 1500.
        $this->assertStringContainsString('$2,500.00', $content);
    }

    public function test_a_retained_lease_to_own_decision_books_no_obligation()
    {
        // Retained is not a buyout: the lease-to-own term simply ended and
        // title passed. Logging it as a buyout put a cost on the register
        // that nobody owes.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20200401',
            'Lease End Date' => now()->addMonth()->format('Y-m-d'),
            'Ownership Type' => 'Purchased',
        ], ['asset_tag' => 'RETAIN-1', 'purchase_date' => '2020-04-01']);

        LeaseDecision::factory()->create([
            'contract_reference' => 'ECI20200401',
            'asset_id' => null,
            'decision_type' => 'retain',
            'status' => 'approved',
            'amount' => 4200,
            'decision_date' => now()->format('Y-m-d'),
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.aro-register', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('ECI20200401')
            ->assertSee(trans('admin/purchase-orders/general.aro_action_retained'))
            // The amount is neither shown nor totalled.
            ->assertDontSee('$4,200.00');
    }

    public function test_the_lease_decision_form_offers_retained()
    {
        $decision = LeaseDecision::factory()->create(['decision_type' => 'buyout']);

        $this->actingAs($this->superuser())
            ->get(route('lease-decisions.edit', $decision))
            ->assertOk()
            ->assertSee('value="retain"', false)
            ->assertSee(trans('admin/lease-decisions/general.type_retain'))
            ->assertSee(trans('admin/lease-decisions/general.type_retain_help'));
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
        $archived = Statuslabel::factory()->archived()->create();
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
            ->assertSee(trans('admin/purchase-orders/general.report_rent_costs'))
            // Data health closes the page — too granular for the
            // procurement stream, at home with the leases it describes.
            ->assertSee(trans('admin/purchase-orders/general.report_lease_data_health'));

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

    public function test_rent_costs_rests_in_natural_contract_name_order_and_offers_column_sorting()
    {
        // Three contracts whose rents rank the opposite way to their names,
        // and a "#10" that a plain string sort would file before "#9".
        foreach ([['#9', '100'], ['#10', '300'], ['#2', '200']] as [$suffix, $rent]) {
            $this->seedLeaseAsset([
                'Lease Contract ID' => 'ECI-ORDER'.$suffix,
                'Lease Contract Name' => 'Devices Leases FY26-27 '.$suffix,
                'Lease Rent' => $rent,
                'Lease End Date' => now()->startOfYear()->addYears(3)->format('Y-m-d'),
            ], ['purchase_date' => now()->subYear()->format('Y-m-d')]);
        }

        $fy = now()->month >= 4 ? now()->year : now()->year - 1;

        $content = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.rent-costs', ['fiscal_year' => sprintf('FY%d-%02d', $fy, ($fy + 1) % 100)]))
            ->assertOk()
            ->getContent();

        $positions = array_map(
            fn ($name) => strpos($content, 'Devices Leases FY26-27 '.$name),
            ['#2', '#9', '#10'],
        );
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'contracts rest in natural name order, not by rent');

        // Every column is sortable in the browser from here.
        $this->assertStringContainsString('data-sortable="1"', $content);
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

        // Rent Costs and Lease Data Health moved to /procurement/leasing,
        // where the portfolio they describe already lives.
        $this->assertStringNotContainsString('proc-report_rent_costs', $content);
        $this->assertStringNotContainsString('proc-report_lease_data_health', $content);

        // The register dropped the ARO initialism.
        $this->assertSame('Buyout Register', trans('admin/purchase-orders/general.report_aro_register'));
    }

    public function test_reports_sit_under_the_stage_a_reader_looks_for_them_in()
    {
        // Stage is where the reader goes looking, not where the work
        // happens. The Buyout Register and Capital Spend are consulted
        // while setting a year's envelope; the Disposition Grid describes
        // devices whose lifecycle is over.
        $content = $this->actingAs($this->superuser())
            ->get(route('reports.procurement'))
            ->assertOk()
            ->getContent();

        $stageOf = function (string $report) use ($content) {
            $at = strpos($content, 'data-pr-report="proc-'.$report.'"');
            $this->assertNotFalse($at, "no pill for {$report}");
            // The pill's own column, i.e. the nearest stage above it.
            $before = substr($content, 0, $at);
            preg_match_all('/data-report-stage="([a-z]+)"/', $before, $m);

            return end($m[1]);
        };

        $this->assertSame('budgeting', $stageOf('report_aro_register'));
        $this->assertSame('budgeting', $stageOf('report_capital'));
        $this->assertSame('completed', $stageOf('report_disposition_grid'));

        // The grid leads its column rather than trailing the reports that
        // were already there.
        $this->assertLessThan(
            strpos($content, 'data-pr-report="proc-report_po_disposition"'),
            strpos($content, 'data-pr-report="proc-report_disposition_grid"')
        );
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

        // A device on the same PO delivered after the year end still counts
        // here: the spend belongs to the year of its purchase order, which
        // is where its budget lives. Scoping by purchase_date instead put
        // this dollar in the new year, where it had no budget and had
        // already been deducted from the carry-forward.
        $other = Asset::factory()->create(['purchase_cost' => 5000.00, 'purchase_date' => '2026-06-01']);
        Asset::query()->whereKey($other->id)->update(['po_number' => 'P0099001']);

        // A device on a PO belonging to another year does not leak in.
        PurchaseOrder::factory()->create([
            'po_number' => 'P0099002', 'budget' => 1000, 'fiscal_year' => 'FY2026-27',
        ]);
        $elsewhere = Asset::factory()->create(['purchase_cost' => 777.00, 'purchase_date' => '2025-06-01']);
        Asset::query()->whereKey($elsewhere->id)->update(['po_number' => 'P0099002']);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement.po-budget', ['fiscal_year' => 'FY2025-26']))
            ->assertOk()
            ->assertSee('P0099001')
            // (1000 + 150 warranty) + 2000 + the 5000 delivered in June.
            ->assertSee('$8,150.00')
            ->assertDontSee('$777.00');
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
        BudgetAllocation::create([
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

    public function test_a_capital_request_po_consumes_the_preapproval_it_was_drafted_from()
    {
        // The envelope estimates a replacement purchase. Once that purchase
        // is real, its PO budget is in the pot on its own account — so the
        // envelope must stand down by the same amount instead of funding the
        // replacement a second time.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20220901',
            'Lease End Date' => '2026-09-01',
        ], ['serial' => 'CONSUMED1', 'purchase_cost' => 1500.50]);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'po_number' => 'P0099001',
            'fiscal_year' => 'FY2026-27',
            'budget' => 1200.00,
            'order_date' => '2026-05-01',
            'status' => 'open',
        ]);

        $requisition = Requisition::create([
            'title' => 'Devices Capital Request FY2026-27',
            'fiscal_year' => 'FY2026-27',
            'capital_request_fy' => 'FY2026-27',
            'status' => 'ordered',
            'created_by' => $this->superuser()->id,
        ]);
        $requisition->purchase_order_id = $purchaseOrder->id;
        $requisition->save();

        // Pot = the PO's own budget (1,200.00) + what is left of the
        // envelope (1,500.50 - 1,200.00), not both in full.
        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertViewHas('leaseExpiryTotal', fn ($total) => abs($total - 1500.50) < 0.01)
            ->assertViewHas('leaseExpiryApplied', fn ($applied) => abs($applied - 300.50) < 0.01)
            ->assertViewHas('totalBudget', fn ($budget) => abs($budget - 1500.50) < 0.01);
    }

    public function test_a_capital_po_larger_than_the_envelope_does_not_credit_it_back()
    {
        // A PO that overruns the envelope consumes all of it and no more —
        // the excess is spend beyond pre-approval, never negative budget.
        $this->seedLeaseAsset([
            'Lease Contract ID' => 'ECI20220901',
            'Lease End Date' => '2026-09-01',
        ], ['serial' => 'OVERRUN1', 'purchase_cost' => 1500.50]);

        $purchaseOrder = PurchaseOrder::factory()->create([
            'po_number' => 'P0099002',
            'fiscal_year' => 'FY2026-27',
            'budget' => 4000.00,
            'order_date' => '2026-05-01',
            'status' => 'open',
        ]);

        $requisition = Requisition::create([
            'title' => 'Devices Capital Request FY2026-27',
            'fiscal_year' => 'FY2026-27',
            'capital_request_fy' => 'FY2026-27',
            'status' => 'ordered',
            'created_by' => $this->superuser()->id,
        ]);
        $requisition->purchase_order_id = $purchaseOrder->id;
        $requisition->save();

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertViewHas('leaseExpiryApplied', fn ($applied) => abs($applied) < 0.01)
            ->assertViewHas('totalBudget', fn ($budget) => abs($budget - 4000.00) < 0.01);
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
