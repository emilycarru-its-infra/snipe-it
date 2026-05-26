<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BudgetAllocation;
use App\Models\ConsumableTransaction;
use App\Models\CustomField;
use App\Models\FacultyAgreement;
use App\Models\LeaseDecision;
use App\Models\LeaseSchedule;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use League\Csv\EscapeFormula;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance-facing reports for the procurement module. The four key reports
 * (PO budget, invoices, capital spend, refresh forecast) render on screen
 * as a live page, with a CSV download as the secondary option. Receiving
 * and tax remain download-only.
 *
 * Each report is built once as a structured array — columns, records and
 * an optional footer — and then either rendered or streamed as CSV.
 */
class ProcurementReportsController extends Controller
{
    /**
     * Procurement dashboard: budget/spend summary cards, charts and links
     * to the individual reports.
     */
    public function index(Request $request)
    {
        $this->authorize('reports.procurement.view');

        // Fiscal years available across purchase orders and planned orders.
        $allFiscalYears = PurchaseOrder::whereNotNull('fiscal_year')->distinct()->pluck('fiscal_year')
            ->merge(Order::planned()->whereNotNull('fiscal_year')->distinct()->pluck('fiscal_year'))
            ->unique()->sort()->values();

        $selectedFy = $request->query('fiscal_year');
        if (! $allFiscalYears->contains($selectedFy)) {
            $selectedFy = null;
        }

        $purchaseOrders = PurchaseOrder::when($selectedFy, fn ($query) => $query->where('fiscal_year', $selectedFy))
            ->orderBy('po_number')
            ->get();

        $poRows = [];
        $totalCommitted = 0.0;
        $totalInvoiced = 0.0;
        $committedByFy = [];

        foreach ($purchaseOrders as $po) {
            $budget = (float) $po->budget;
            $committed = $po->committedTotal();

            $totalCommitted += $committed;
            $totalInvoiced += $po->invoicedTotal();

            $poRows[] = [
                'po_number' => $po->po_number,
                'budget' => $budget,
                'committed' => $committed,
            ];

            $fy = $po->fiscal_year ?: '—';
            $committedByFy[$fy] = ($committedByFy[$fy] ?? 0) + $committed;
        }

        // Approved Budget is sourced from the budget_allocations ledger,
        // not per-PO budgets. Each allocation is one event (forecast seed,
        // supplemental top-up, or adjustment); summing them yields the
        // year's pot. Without an FY filter, sum the entire ledger.
        $allocationsQuery = BudgetAllocation::query()
            ->when($selectedFy, fn ($q) => $q->where('fiscal_year', $selectedFy))
            ->with('creator')
            ->orderBy('created_at');
        $allocations = $allocationsQuery->get();
        $totalBudget = (float) $allocations->sum('amount');

        // Planned (forecast) spend, grouped by the planned order's fiscal year.
        $plannedByFy = [];
        $plannedTotal = 0.0;

        $plannedOrders = Order::planned()
            ->when($selectedFy, fn ($query) => $query->where('fiscal_year', $selectedFy))
            ->with('items')
            ->get();

        foreach ($plannedOrders as $order) {
            $value = (float) $order->items->sum->lineTotal();
            $plannedTotal += $value;
            $fy = $order->fiscal_year ?: '—';
            $plannedByFy[$fy] = ($plannedByFy[$fy] ?? 0) + $value;
        }

        // Invoiced totals grouped by calendar month.
        $monthly = OrderInvoice::whereNotNull('invoice_date')
            ->when($selectedFy, fn ($query) => $query->whereHas(
                'purchaseOrder',
                fn ($po) => $po->where('fiscal_year', $selectedFy)
            ))
            ->orderBy('invoice_date')
            ->get()
            ->groupBy(fn ($invoice) => $invoice->invoice_date->format('Y-m'))
            ->map(fn ($group) => (float) $group->sum('total'));

        // Assets reaching end-of-life within the next year.
        $eolAssets = Asset::whereNotNull('asset_eol_date')
            ->whereBetween('asset_eol_date', [now()->startOfDay(), now()->addYear()])
            ->get();

        // Lease-end pre-approval — devices whose lease ends in each
        // FY drive that FY's replacement budget (CSI/Macquarie schedules
        // are already pre-approved at signing). The selected-FY card
        // surfaces this; the FY chart overlays it on committed/planned.
        $leaseExpiryByFy = $this->leaseExpiryByFy();
        $leaseExpiryTotal = $selectedFy
            ? (float) ($leaseExpiryByFy[$selectedFy]['cost'] ?? 0.0)
            : (float) array_sum(array_column($leaseExpiryByFy, 'cost'));
        $leaseExpiryCount = $selectedFy
            ? (int) ($leaseExpiryByFy[$selectedFy]['count'] ?? 0)
            : (int) array_sum(array_column($leaseExpiryByFy, 'count'));

        $fiscalYears = array_keys($committedByFy + $plannedByFy + $leaseExpiryByFy);
        sort($fiscalYears);

        // Finance triage counters — what the dashboard reader sees first.
        // Pending-approval invoices answer Mark's monthly "can I pay
        // this?" question; pending lease decisions catch buyout/return
        // calls that haven't been logged yet.
        $pendingApprovalCount = OrderInvoice::where('approval_status', 'pending')
            ->when($selectedFy, fn ($query) => $query->whereHas(
                'purchaseOrder',
                fn ($po) => $po->where('fiscal_year', $selectedFy)
            ))
            ->count();

        $pendingDecisionCount = LeaseDecision::where('status', 'pending')->count();

        // Faculty agreements waiting for a signature — Sohee's chase
        // list. Stuck in 'quoted' or 'agreement_sent' is the failure
        // mode that holds up the Apple account on a pending pickup.
        $facultyAwaitingSignatureCount = FacultyAgreement::whereIn(
            'lifecycle_stage',
            ['quoted', 'agreement_sent']
        )->count();

        // Lease schedules sitting in the chase queue — drafts or
        // awaiting Viktor / Mark's signature. The lessor is blocked
        // from finalising until this clears.
        $scheduleSigningQueueCount = LeaseSchedule::whereIn(
            'lifecycle_stage',
            LeaseSchedule::OPEN_STAGES
        )->count();

        return view('reports/procurement', [
            'pendingApprovalCount' => $pendingApprovalCount,
            'pendingDecisionCount' => $pendingDecisionCount,
            'facultyAwaitingSignatureCount' => $facultyAwaitingSignatureCount,
            'scheduleSigningQueueCount' => $scheduleSigningQueueCount,
            'allFiscalYears' => $allFiscalYears,
            'selectedFy' => $selectedFy,
            'totalBudget' => $totalBudget,
            'totalCommitted' => $totalCommitted,
            'totalInvoiced' => $totalInvoiced,
            'totalRemaining' => $totalBudget - $totalCommitted,
            'plannedTotal' => $plannedTotal,
            'poCount' => $purchaseOrders->count(),
            'orderCount' => Order::actual()
                ->when($selectedFy, fn ($query) => $query->where('fiscal_year', $selectedFy))
                ->count(),
            'invoiceCount' => OrderInvoice::when($selectedFy, fn ($query) => $query->whereHas(
                'purchaseOrder',
                fn ($po) => $po->where('fiscal_year', $selectedFy)
            ))->count(),
            'eolCount' => $eolAssets->count(),
            'eolEstimate' => (float) $eolAssets->sum('purchase_cost'),
            'leaseExpiryTotal' => $leaseExpiryTotal,
            'leaseExpiryCount' => $leaseExpiryCount,
            'poRows' => $poRows,
            'fiscalYears' => array_values($fiscalYears),
            'committedByFy' => $committedByFy,
            'plannedByFy' => $plannedByFy,
            'leaseExpiryByFy' => $leaseExpiryByFy,
            'monthlyLabels' => $monthly->keys()->all(),
            'monthlyValues' => array_values($monthly->all()),
            'allocations' => $allocations,
            'budgetSourceLabels' => BudgetAllocation::SOURCES,
        ]);
    }

    public function poBudget(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'po-budget-report',
            trans('admin/purchase-orders/general.report_po_budget'),
            'reports.procurement.po-budget',
            $this->poBudgetReport()
        );
    }

    public function invoices(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'invoice-reconciliation-report',
            trans('admin/purchase-orders/general.report_invoices'),
            'reports.procurement.invoices',
            $this->invoicesReport()
        );
    }

    public function capital(Request $request)
    {
        $this->authorize('reports.procurement.view');

        $forecast = $request->query('mode') === 'forecast';

        return $this->render(
            $request,
            'capital-spend-report',
            trans('admin/purchase-orders/general.report_capital'),
            'reports.procurement.capital',
            $this->capitalReport($forecast),
            $this->capitalModeToggle($forecast),
            ['mode' => $forecast ? 'forecast' : 'actual']
        );
    }

    public function refreshForecast(Request $request)
    {
        $this->authorize('reports.procurement.view');

        $report = $this->refreshForecastReport();

        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv('refresh-forecast-report', $report);
        }

        return view('reports/procurement/forecast', [
            'reportTitle' => trans('admin/purchase-orders/general.report_forecast'),
            'columns' => $report['columns'],
            'rows' => $report['records'],
            'footer' => $report['footer'] ?? null,
            'downloadUrl' => route('reports.procurement.forecast', ['format' => 'csv']),
            'canCreate' => Gate::allows('create', Order::class),
        ]);
    }

    /**
     * Generate a planned order from devices selected on the Refresh
     * Forecast report. Each selected end-of-life asset becomes a planned
     * line item carrying its replacement-cost estimate.
     */
    public function createPlannedOrder(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $validated = $request->validate([
            'assets' => 'required|array|min:1',
            'assets.*' => 'integer|exists:assets,id',
            'order_number' => 'required|string|max:191',
            'fiscal_year' => 'nullable|string|max:191',
        ]);

        // Skip any device that already has a planned replacement so the
        // forecast can't double-book the same asset.
        $alreadyPlanned = OrderItem::whereIn('replaces_asset_id', $validated['assets'])
            ->pluck('replaces_asset_id')
            ->all();

        $assets = Asset::with('model')
            ->whereIn('id', $validated['assets'])
            ->whereNotIn('id', $alreadyPlanned)
            ->get();

        if ($assets->isEmpty()) {
            return redirect()->route('reports.procurement.forecast')
                ->with('error', trans('admin/purchase-orders/general.forecast_none_selected'));
        }

        $order = new Order;
        $order->order_number = $validated['order_number'];
        $order->status = 'ordered';
        $order->is_planned = true;
        $order->fiscal_year = $validated['fiscal_year'] ?? null;
        $order->created_by = auth()->id();

        if (! $order->save()) {
            return redirect()->route('reports.procurement.forecast')
                ->withInput()->withErrors($order->getErrors());
        }

        foreach ($assets as $asset) {
            OrderItem::create([
                'order_id' => $order->id,
                'replaces_asset_id' => $asset->id,
                'description' => trans('admin/purchase-orders/general.forecast_line_description', [
                    'tag' => $asset->asset_tag,
                    'model' => $asset->model?->name ?: trans('general.na'),
                ]),
                'quantity' => 1,
                'unit_cost' => $asset->purchase_cost,
            ]);
        }

        return redirect()->route('orders.show', $order->id)
            ->with('success', trans('admin/purchase-orders/general.forecast_planned_created', ['count' => $assets->count()]));
    }

    public function receiving(): StreamedResponse
    {
        $this->authorize('reports.procurement.view');

        return $this->streamReportCsv('receiving-status-report', $this->receivingReport());
    }

    public function leasesOperational(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'leases-operational-report',
            trans('admin/purchase-orders/general.report_leases_operational'),
            'reports.procurement.leases-operational',
            $this->leasesOperationalReport()
        );
    }

    public function leasesFinancial(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'leases-financial-report',
            trans('admin/purchase-orders/general.report_leases_financial'),
            'reports.procurement.leases-financial',
            $this->leasesFinancialReport()
        );
    }

    public function csiSchedule(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'csi-schedule-reconciliation-report',
            trans('admin/purchase-orders/general.report_csi_schedule'),
            'reports.procurement.csi-schedule',
            $this->csiScheduleReport()
        );
    }

    public function invoiceApproval(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'invoice-approval-queue',
            trans('admin/purchase-orders/general.report_invoice_approval'),
            'reports.procurement.invoice-approval',
            $this->invoiceApprovalReport($request->query('status'), $request->query('attestation_type'))
        );
    }

    public function facultyLedger(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'faculty-program-ledger',
            trans('admin/purchase-orders/general.report_faculty_ledger'),
            'reports.procurement.faculty-ledger',
            $this->facultyLedgerReport($request->query('agreement_type'), $request->query('stage'))
        );
    }

    public function scheduleSigningQueue(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'schedule-signing-queue',
            trans('admin/purchase-orders/general.report_schedule_signing'),
            'reports.procurement.schedule-signing',
            $this->scheduleSigningQueueReport($request->query('stage'))
        );
    }

    /**
     * Mark or unmark an invoice as approved-to-pay. Single PATCH endpoint
     * the Invoice Approval Queue posts to so AP can clear the queue
     * inline instead of hopping to the order page.
     */
    public function updateInvoiceApproval(Request $request, OrderInvoice $invoice): RedirectResponse
    {
        $this->authorize('reports.procurement.view');

        $validated = $request->validate([
            'approval_status' => 'required|string|in:pending,approved,disputed',
            'is_final_invoice' => 'nullable|boolean',
            'usage_tag' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:65535',
        ]);

        $invoice->approval_status = $validated['approval_status'];
        $invoice->is_final_invoice = (bool) ($validated['is_final_invoice'] ?? false);
        if (array_key_exists('usage_tag', $validated)) {
            $invoice->usage_tag = $validated['usage_tag'];
        }
        if (array_key_exists('notes', $validated)) {
            $invoice->notes = $validated['notes'];
        }

        if ($validated['approval_status'] === 'approved') {
            $invoice->approved_at = now();
            $invoice->approved_by = auth()->id();
        } elseif ($validated['approval_status'] === 'pending') {
            // Re-opening sweeps the approval signature so the audit trail
            // stays honest — an invoice that goes pending → approved →
            // pending shouldn't keep the original approver's name on it.
            $invoice->approved_at = null;
            $invoice->approved_by = null;
        }

        $invoice->save();

        return redirect()->route('reports.procurement.invoice-approval', $request->only('status'))
            ->with('success', trans('admin/purchase-orders/general.invoice_approval_updated'));
    }

    public function leaseDecisions(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'lease-decisions-report',
            trans('admin/purchase-orders/general.report_lease_decisions'),
            'reports.procurement.lease-decisions',
            $this->leaseDecisionsReport($request->query('status'))
        );
    }

    /**
     * GL Journal Transfer — the finance-ready chargeback form. Every
     * consumable (toner) checked out to a GL-coded printer is one line;
     * lines are grouped by GL code with a per-GL subtotal (the amount to
     * journal to that department) and a grand total. `?fiscal_year=` and
     * `?status=` narrow it.
     */
    public function glJournalTransfer(Request $request)
    {
        $this->authorize('reports.procurement.view');

        $fiscalYear = $request->query('fiscal_year');
        $status = $request->query('status');

        $controls = view('reports.procurement._gl-transfer-controls', [
            'fiscalYear' => $fiscalYear,
            'status' => $status,
            'draftCount' => ConsumableTransaction::where('status', ConsumableTransaction::STATUS_DRAFT)
                ->when($fiscalYear, fn ($query) => $query->where('fiscal_year', $fiscalYear))
                ->count(),
            'postedCount' => ConsumableTransaction::where('status', ConsumableTransaction::STATUS_POSTED)
                ->when($fiscalYear, fn ($query) => $query->where('fiscal_year', $fiscalYear))
                ->count(),
        ])->render();

        return $this->render(
            $request,
            'gl-journal-transfer',
            trans('admin/purchase-orders/general.report_gl_transfer'),
            'reports.procurement.gl-transfer',
            $this->glJournalTransferReport($fiscalYear, $status),
            $controls,
            array_filter(['fiscal_year' => $fiscalYear, 'status' => $status]),
        );
    }

    /**
     * Mark draft GL transactions as posted — the "journal transfer has
     * been generated and handed to Finance" step. Scoped to a fiscal year
     * when one is supplied.
     */
    public function markGlTransactionsPosted(Request $request): RedirectResponse
    {
        $this->authorize('reports.procurement.view');

        $fiscalYear = $request->input('fiscal_year');

        $posted = ConsumableTransaction::draft()
            ->when($fiscalYear, fn ($query) => $query->where('fiscal_year', $fiscalYear))
            ->update(['status' => ConsumableTransaction::STATUS_POSTED]);

        return redirect()
            ->route('reports.procurement.gl-transfer', array_filter(['fiscal_year' => $fiscalYear]))
            ->with('success', trans('admin/purchase-orders/general.gl_transfer_posted', ['count' => $posted]));
    }

    /**
     * Mark posted GL transactions as transferred — Finance has confirmed
     * the journal entry went through. Closes the lifecycle. Scoped to a
     * fiscal year when one is supplied.
     */
    public function markGlTransactionsTransferred(Request $request): RedirectResponse
    {
        $this->authorize('reports.procurement.view');

        $fiscalYear = $request->input('fiscal_year');

        $transferred = ConsumableTransaction::where('status', ConsumableTransaction::STATUS_POSTED)
            ->when($fiscalYear, fn ($query) => $query->where('fiscal_year', $fiscalYear))
            ->update(['status' => ConsumableTransaction::STATUS_TRANSFERRED]);

        return redirect()
            ->route('reports.procurement.gl-transfer', array_filter(['fiscal_year' => $fiscalYear]))
            ->with('success', trans('admin/purchase-orders/general.gl_transfer_transferred', ['count' => $transferred]));
    }

    public function poDisposition(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'po-disposition-report',
            trans('admin/purchase-orders/general.report_po_disposition'),
            'reports.procurement.po-disposition',
            $this->poDispositionReport()
        );
    }

    public function extensionWatch(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'extension-watch-report',
            trans('admin/purchase-orders/general.report_extension_watch'),
            'reports.procurement.extension-watch',
            $this->extensionWatchReport()
        );
    }

    public function aroRegister(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'aro-register-report',
            trans('admin/purchase-orders/general.report_aro_register'),
            'reports.procurement.aro-register',
            $this->aroRegisterReport()
        );
    }

    public function assetLeaseDetail(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'asset-lease-detail-report',
            trans('admin/purchase-orders/general.report_asset_lease_detail'),
            'reports.procurement.asset-lease-detail',
            $this->assetLeaseDetailReport()
        );
    }

    public function poDrilldown(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'po-drilldown-report',
            trans('admin/purchase-orders/general.report_po_drilldown'),
            'reports.procurement.po-drilldown',
            $this->poDrilldownReport()
        );
    }

    public function dispositionGrid(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'disposition-grid-report',
            trans('admin/purchase-orders/general.report_disposition_grid'),
            'reports.procurement.disposition-grid',
            $this->dispositionGridReport()
        );
    }

    public function creditTerminationLedger(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'credit-termination-ledger',
            trans('admin/purchase-orders/general.report_credit_ledger'),
            'reports.procurement.credit-ledger',
            $this->creditTerminationReport()
        );
    }

    public function lessorBreakdown(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'lessor-breakdown-report',
            trans('admin/purchase-orders/general.report_lessor_breakdown'),
            'reports.procurement.lessor-breakdown',
            $this->lessorBreakdownReport()
        );
    }

    public function pstApplicability(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'pst-applicability-report',
            trans('admin/purchase-orders/general.report_pst_applicability'),
            'reports.procurement.pst-applicability',
            $this->pstApplicabilityReport()
        );
    }

    public function tax(): StreamedResponse
    {
        $this->authorize('reports.procurement.view');

        return $this->streamReportCsv('tax-summary-report', $this->taxReport());
    }

    /**
     * Per-purchase-order budget vs. spend.
     */
    private function poBudgetReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('admin/purchase-orders/general.title'),
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/purchase-orders/general.cost_center'),
            trans('general.supplier'),
            trans('admin/purchase-orders/general.status'),
            trans('admin/purchase-orders/general.budget'),
            trans('admin/purchase-orders/general.invoiced'),
            trans('admin/purchase-orders/general.committed'),
            trans('admin/purchase-orders/general.remaining'),
            trans('admin/purchase-orders/general.over_budget'),
            trans('admin/purchase-orders/general.orders'),
        ];

        $purchaseOrders = PurchaseOrder::with('supplier', 'orders.invoices', 'orders.items')
            ->orderBy('po_number')
            ->get();

        $records = [];
        $totalBudget = $totalInvoiced = $totalCommitted = $totalRemaining = $totalOrders = 0.0;

        foreach ($purchaseOrders as $po) {
            $invoiced = $po->invoicedTotal();
            $committed = $po->committedTotal();
            $remaining = $po->remaining();

            $totalBudget += (float) $po->budget;
            $totalInvoiced += $invoiced;
            $totalCommitted += $committed;
            $totalRemaining += ($remaining ?? 0);
            $totalOrders += $po->orders->count();

            $records[] = [
                'class' => $po->isOverBudget() ? 'danger' : '',
                'cells' => [
                    $po->po_number,
                    (string) $po->title,
                    (string) $po->fiscal_year,
                    (string) $po->cost_center,
                    (string) $po->supplier?->name,
                    $po->status,
                    $this->money($po->budget),
                    $this->money($invoiced),
                    $this->money($committed),
                    $remaining === null ? '' : $this->money($remaining),
                    $po->isOverBudget() ? trans('general.yes') : trans('general.no'),
                    $po->orders->count(),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '', '',
            $this->money($totalBudget),
            $this->money($totalInvoiced),
            $this->money($totalCommitted),
            $this->money($totalRemaining),
            '',
            (int) $totalOrders,
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Every vendor invoice with its purchase order and order linkage.
     */
    private function invoicesReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('general.order_number'),
            trans('admin/orders/general.invoice_number'),
            trans('admin/orders/general.invoice_date'),
            trans('admin/orders/general.subtotal'),
            trans('admin/orders/general.tax_gst'),
            trans('admin/orders/general.tax_pst'),
            trans('admin/orders/general.shipping'),
            trans('admin/orders/general.total'),
            trans('admin/orders/general.line_items'),
        ];

        $invoices = OrderInvoice::with('order.purchaseOrder', 'items')
            ->orderBy('invoice_number')
            ->get();

        $records = [];
        $totalSubtotal = $totalGst = $totalPst = $totalShipping = $totalTotal = 0.0;

        foreach ($invoices as $invoice) {
            $totalSubtotal += (float) $invoice->subtotal;
            $totalGst += (float) $invoice->tax_gst;
            $totalPst += (float) $invoice->tax_pst;
            $totalShipping += (float) $invoice->shipping;
            $totalTotal += (float) $invoice->total;

            $records[] = [
                'class' => '',
                'cells' => [
                    (string) $invoice->order?->purchaseOrder?->po_number,
                    (string) $invoice->order?->order_number,
                    $invoice->invoice_number,
                    $this->dateString($invoice->invoice_date),
                    $this->money($invoice->subtotal),
                    $this->money($invoice->tax_gst),
                    $this->money($invoice->tax_pst),
                    $this->money($invoice->shipping),
                    $this->money($invoice->total),
                    $invoice->items->count(),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '',
            $this->money($totalSubtotal),
            $this->money($totalGst),
            $this->money($totalPst),
            $this->money($totalShipping),
            $this->money($totalTotal),
            '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Per-order receiving progress.
     */
    private function receivingReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('general.order_number'),
            trans('admin/orders/general.status'),
            trans('general.supplier'),
            trans('admin/orders/general.order_date'),
            trans('admin/orders/general.line_items'),
            trans('admin/orders/general.received'),
            trans('admin/orders/general.not_received'),
        ];

        $orders = Order::actual()
            ->with('purchaseOrder', 'supplier', 'items')
            ->orderBy('order_number')
            ->get();

        $records = [];
        foreach ($orders as $order) {
            $total = $order->items->count();
            $received = $order->items->whereNotNull('received_at')->count();
            $records[] = [
                'class' => '',
                'cells' => [
                    (string) $order->purchaseOrder?->po_number,
                    $order->order_number,
                    $order->status,
                    (string) $order->supplier?->name,
                    $this->dateString($order->order_date),
                    $total,
                    $received,
                    $total - $received,
                ],
            ];
        }

        return ['columns' => $columns, 'records' => $records];
    }

    /**
     * GST / PST totals per purchase order.
     */
    private function taxReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/orders/general.subtotal'),
            trans('admin/orders/general.tax_gst'),
            trans('admin/orders/general.tax_pst'),
            trans('admin/orders/general.shipping'),
            trans('admin/orders/general.total'),
        ];

        $purchaseOrders = PurchaseOrder::with('orders.invoices')->orderBy('po_number')->get();

        $records = [];
        foreach ($purchaseOrders as $po) {
            $invoices = $po->orders->flatMap->invoices;
            $records[] = [
                'class' => '',
                'cells' => [
                    $po->po_number,
                    (string) $po->fiscal_year,
                    $this->money($invoices->sum('subtotal')),
                    $this->money($invoices->sum('tax_gst')),
                    $this->money($invoices->sum('tax_pst')),
                    $this->money($invoices->sum('shipping')),
                    $this->money($invoices->sum('total')),
                ],
            ];
        }

        $orphanInvoices = OrderInvoice::whereHas('order', function ($query) {
            $query->whereNull('purchase_order_id');
        })->get();

        if ($orphanInvoices->isNotEmpty()) {
            $records[] = [
                'class' => '',
                'cells' => [
                    trans('admin/purchase-orders/general.none'),
                    '',
                    $this->money($orphanInvoices->sum('subtotal')),
                    $this->money($orphanInvoices->sum('tax_gst')),
                    $this->money($orphanInvoices->sum('tax_pst')),
                    $this->money($orphanInvoices->sum('shipping')),
                    $this->money($orphanInvoices->sum('total')),
                ],
            ];
        }

        return ['columns' => $columns, 'records' => $records];
    }

    /**
     * Capital spend grouped by fiscal year and cost centre. In forecast
     * mode, planned (forecast) orders are appended grouped by fiscal year.
     */
    private function capitalReport(bool $forecast = false): array
    {
        $columns = [
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/purchase-orders/general.cost_center'),
            trans('admin/purchase-orders/general.purchase_orders'),
            trans('admin/purchase-orders/general.budget'),
            trans('admin/purchase-orders/general.committed'),
            trans('admin/purchase-orders/general.remaining'),
        ];

        $purchaseOrders = PurchaseOrder::with('orders.invoices', 'orders.items')->get();

        $groups = $purchaseOrders->groupBy(function ($po) {
            return ($po->fiscal_year ?: '—').'||'.($po->cost_center ?: '—');
        });

        $records = [];
        $totalBudget = $totalCommitted = $totalPlanned = 0.0;

        foreach ($groups as $key => $group) {
            [$fiscalYear, $costCenter] = explode('||', $key);
            $budget = $group->sum(fn ($po) => (float) $po->budget);
            $committed = $group->sum(fn ($po) => $po->committedTotal());
            $totalBudget += $budget;
            $totalCommitted += $committed;

            $records[] = [
                'class' => '',
                'cells' => [
                    $fiscalYear,
                    $costCenter,
                    $group->count(),
                    $this->money($budget),
                    $this->money($committed),
                    $this->money($budget - $committed),
                ],
            ];
        }

        if ($forecast) {
            $plannedGroups = Order::planned()->with('items')->get()
                ->groupBy(fn ($order) => $order->fiscal_year ?: '—');

            foreach ($plannedGroups as $fiscalYear => $group) {
                $planned = $group->sum(
                    fn ($order) => $order->items->sum(
                        fn ($item) => ((float) $item->unit_cost * (int) $item->quantity) + (float) $item->warranty_cost
                    )
                );
                $totalPlanned += $planned;

                $records[] = [
                    'class' => 'info',
                    'cells' => [
                        $fiscalYear,
                        trans('admin/orders/general.planned'),
                        $group->count(),
                        '',
                        $this->money($planned),
                        '',
                    ],
                ];
            }
        }

        $footer = [
            trans('admin/orders/general.total'), '', '',
            $this->money($totalBudget),
            $this->money($totalCommitted + $totalPlanned),
            $this->money($totalBudget - $totalCommitted - $totalPlanned),
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * The Actual / Forecast mode toggle for the Capital Spend report.
     */
    private function capitalModeToggle(bool $forecast): string
    {
        return '<div class="btn-group" role="group">'
            .'<a href="'.route('reports.procurement.capital').'" class="btn btn-sm '.($forecast ? 'btn-default' : 'btn-primary').'">'
            .e(trans('admin/purchase-orders/general.mode_actual')).'</a>'
            .'<a href="'.route('reports.procurement.capital', ['mode' => 'forecast']).'" class="btn btn-sm '.($forecast ? 'btn-primary' : 'btn-default').'">'
            .e(trans('admin/purchase-orders/general.mode_forecast')).'</a>'
            .'</div> ';
    }

    /**
     * Assets reaching end-of-life within the next year — the refresh
     * pipeline. purchase_cost stands in as the replacement-cost estimate.
     */
    private function refreshForecastReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.forecast_asset_tag'),
            trans('admin/purchase-orders/general.forecast_asset_name'),
            trans('admin/purchase-orders/general.forecast_model'),
            trans('admin/purchase-orders/general.forecast_serial'),
            trans('admin/purchase-orders/general.forecast_purchase_date'),
            trans('admin/purchase-orders/general.forecast_eol_date'),
            trans('admin/purchase-orders/general.forecast_estimate'),
            trans('admin/purchase-orders/general.forecast_status'),
            trans('general.supplier'),
        ];

        $assets = Asset::with('model', 'supplier', 'status')
            ->whereNotNull('asset_eol_date')
            ->whereBetween('asset_eol_date', [now()->startOfDay(), now()->addYear()])
            ->orderBy('asset_eol_date')
            ->get();

        // Devices that already have a planned replacement line item are
        // flagged so the forecast view can show them as planned.
        $plannedAssetIds = OrderItem::whereIn('replaces_asset_id', $assets->pluck('id'))
            ->pluck('replaces_asset_id')
            ->all();

        $records = [];
        $totalEstimate = 0.0;

        foreach ($assets as $asset) {
            $totalEstimate += (float) $asset->purchase_cost;
            $planned = in_array($asset->id, $plannedAssetIds, true);
            $records[] = [
                'class' => $planned ? 'success' : '',
                'asset_id' => $asset->id,
                'planned' => $planned,
                'cells' => [
                    (string) $asset->asset_tag,
                    (string) $asset->name,
                    (string) $asset->model?->name,
                    (string) $asset->serial,
                    $this->dateString($asset->purchase_date),
                    $this->dateString($asset->asset_eol_date),
                    $this->money($asset->purchase_cost),
                    (string) $asset->status?->name,
                    (string) $asset->supplier?->name,
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '', '',
            $this->money($totalEstimate),
            '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Lease custom fields live on the assets table under generated columns
     * (e.g. `_snipeit_lease_contract_id_42`). Look the columns up by field
     * name so the report keeps working if the field IDs shift between
     * environments.
     */
    private function leaseFieldColumns(): array
    {
        // Same field names the sharepoint.csv export uses, so reports
        // and the SharePoint hand-off see the same dataset.
        $names = [
            'contract_id' => 'Lease Contract ID',
            'contract_name' => 'Lease Contract Name',
            'lease_end_date' => 'Lease End Date',
            'ownership_type' => 'Ownership Type',
            'lease_rent' => 'Lease Rent',
            'buyout_cost' => 'Buyout Cost',
            'usage' => 'Usage',
            'area' => 'Area',
            'decommission_date' => 'Decommission Date',
            'book_value' => 'Book Value',
        ];

        $columns = [];
        foreach ($names as $key => $name) {
            $field = CustomField::where('name', $name)->first();
            $columns[$key] = $field?->db_column;
        }

        return $columns;
    }

    /**
     * Whether a Lease Contract ID looks like a real contract reference
     * (matches the same allow-list as the TDX contract sync).
     */
    private function isValidContractId(?string $contractId): bool
    {
        if (! $contractId) {
            return false;
        }

        if (in_array($contractId, ['-', 'N/A', 'n/a', 'None'], true)) {
            return false;
        }

        return str_starts_with($contractId, 'ECI') || str_starts_with($contractId, '301452-');
    }

    /**
     * CSI Leasing handles the 301452-* schedules; Macquarie owns the
     * ECI* contracts. Mirrors the provider mapping in the TDX sync.
     */
    private function contractProvider(string $contractId): string
    {
        return str_starts_with($contractId, '301452-') ? 'CSI Leasing' : 'Macquarie';
    }

    /**
     * Convert a Lease End Date string to the ECU fiscal-year label (Jul-Jun).
     * A July-December end date belongs to FY{Y}-{Y+1}; a January-June end
     * date belongs to FY{Y-1}-{Y}.
     */
    private function fiscalYearFromEndDate(?string $endDateStr): ?string
    {
        if (empty($endDateStr)) {
            return null;
        }

        $endDate = null;
        foreach (['Y-m-d', 'm/d/Y', 'Y/m/d', 'd/m/Y'] as $format) {
            $endDate = \DateTime::createFromFormat($format, $endDateStr);
            if ($endDate !== false) {
                break;
            }
        }

        if (! $endDate) {
            return null;
        }

        $month = (int) $endDate->format('m');
        $year = (int) $endDate->format('Y');

        $start = $month >= 7 ? $year : $year - 1;
        $end = $start + 1;

        return sprintf('FY%02d-%02d', $start % 100, $end % 100);
    }

    /**
     * Devices whose lease end falls within each FY, with $-rollup.
     * Drives the "Lease-end pre-approval" card and the third dataset
     * on the FY chart: a lease ending in FYNN is the implicit
     * replacement budget for FYNN (CSI/Macquarie already pre-approved
     * the equivalent spend when the original schedule was signed).
     *
     * Buyout and archived assets are excluded — they're no longer
     * commitments. purchase_cost stands in as the replacement-cost
     * estimate (same convention as the EOL refresh forecast).
     */
    private function leaseExpiryByFy(): array
    {
        $columns = $this->leaseFieldColumns();
        $endDateColumn = $columns['lease_end_date'];
        $contractIdColumn = $columns['contract_id'];

        if (! $endDateColumn || ! $contractIdColumn) {
            return [];
        }

        $assets = Asset::with('status')
            ->whereNotNull($endDateColumn)
            ->where($endDateColumn, '!=', '')
            ->whereNotNull($contractIdColumn)
            ->where($contractIdColumn, '!=', '')
            ->get();

        $byFy = [];
        foreach ($assets as $asset) {
            if (! $this->isValidContractId($asset->{$contractIdColumn})) {
                continue;
            }

            $statusName = (string) $asset->status?->name;
            $statusMeta = (string) $asset->status?->status_meta;
            if ($statusMeta === 'archived'
                || in_array($statusName, ['Active (Buyouts)', 'Active (Legacy)'], true)) {
                continue;
            }

            $fy = $this->fiscalYearFromEndDate($asset->{$endDateColumn});
            if (! $fy) {
                continue;
            }

            $byFy[$fy] ??= ['count' => 0, 'cost' => 0.0];
            $byFy[$fy]['count']++;
            $byFy[$fy]['cost'] += (float) $asset->purchase_cost;
        }

        ksort($byFy);
        return $byFy;
    }

    /**
     * Group every asset that carries a recognised Lease Contract ID by
     * contract, with the status-meta classification (active / buyout /
     * archived) and cost rollups used by both lease reports.
     *
     * Status names that mark an asset as already removed from a lease:
     *   - "Active (Buyouts)" — equipment purchased outright from the lessor
     *   - "Active (Legacy)" — moved off the lease but still in service
     *   - any status with status_meta = "archived"
     */
    private function groupedLeaseAssets(): array
    {
        $columns = $this->leaseFieldColumns();
        $contractIdColumn = $columns['contract_id'];

        if (! $contractIdColumn) {
            return [];
        }

        $assets = Asset::with('model', 'status')
            ->whereNotNull($contractIdColumn)
            ->where($contractIdColumn, '!=', '')
            ->get();

        $groups = [];
        foreach ($assets as $asset) {
            $contractId = $asset->{$contractIdColumn};
            if (! $this->isValidContractId($contractId)) {
                continue;
            }

            if (! isset($groups[$contractId])) {
                $groups[$contractId] = [
                    'contract_id' => $contractId,
                    'contract_name' => null,
                    'lease_end_date' => null,
                    'provider' => $this->contractProvider($contractId),
                    'assets' => [],
                    'model_counts' => [],
                    'ownership_counts' => [],
                    'usage_counts' => [],
                    'area_counts' => [],
                    'active' => 0,
                    'buyout' => 0,
                    'archived' => 0,
                    'total_cost' => 0.0,
                    'monthly_rent_total' => 0.0,
                    'buyout_cost_total' => 0.0,
                ];
            }

            $group = &$groups[$contractId];
            $group['assets'][] = $asset;

            if (! $group['contract_name'] && $columns['contract_name']) {
                $group['contract_name'] = $asset->{$columns['contract_name']};
            }
            if (! $group['lease_end_date'] && $columns['lease_end_date']) {
                $group['lease_end_date'] = $asset->{$columns['lease_end_date']};
            }

            $modelName = $asset->model?->name ?: trans('general.na');
            $modelName = html_entity_decode($modelName, ENT_QUOTES | ENT_HTML5);
            $group['model_counts'][$modelName] = ($group['model_counts'][$modelName] ?? 0) + 1;

            if ($columns['ownership_type']) {
                $ownership = $asset->{$columns['ownership_type']};
                if (! empty($ownership)) {
                    $group['ownership_counts'][$ownership] = ($group['ownership_counts'][$ownership] ?? 0) + 1;
                }
            }

            if ($columns['usage']) {
                $usage = $asset->{$columns['usage']};
                if (! empty($usage)) {
                    $group['usage_counts'][$usage] = ($group['usage_counts'][$usage] ?? 0) + 1;
                }
            }

            if ($columns['area']) {
                $area = $asset->{$columns['area']};
                if (! empty($area)) {
                    $group['area_counts'][$area] = ($group['area_counts'][$area] ?? 0) + 1;
                }
            }

            if ($columns['lease_rent']) {
                $group['monthly_rent_total'] += $this->parseMoney($asset->{$columns['lease_rent']});
            }
            if ($columns['buyout_cost']) {
                $group['buyout_cost_total'] += $this->parseMoney($asset->{$columns['buyout_cost']});
            }

            $statusName = (string) $asset->status?->name;
            $statusMeta = (string) $asset->status?->status_meta;

            if ($statusMeta === 'archived') {
                $group['archived']++;
            } elseif (in_array($statusName, ['Active (Buyouts)', 'Active (Legacy)'], true)) {
                $group['buyout']++;
            } else {
                $group['active']++;
            }

            $group['total_cost'] += (float) $asset->purchase_cost;
            unset($group);
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Lease overview — TDX-parity view. Groups assets by Lease Contract ID
     * and exposes the same shape the snipe-to-tdx-contracts function pushes
     * to TDX: provider, end date, fiscal year, active/buyout/archived
     * counts, dominant model and ownership type.
     */
    private function leasesOperationalReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.lease_contract_name'),
            trans('admin/purchase-orders/general.lease_provider'),
            trans('admin/purchase-orders/general.lease_end_date'),
            trans('admin/purchase-orders/general.lease_fy_ending'),
            trans('admin/purchase-orders/general.lease_assets'),
            trans('admin/purchase-orders/general.lease_active'),
            trans('admin/purchase-orders/general.lease_buyouts'),
            trans('admin/purchase-orders/general.lease_archived'),
            trans('admin/purchase-orders/general.lease_models'),
            trans('admin/purchase-orders/general.lease_ownership'),
        ];

        $records = [];
        $totalAssets = $totalActive = $totalBuyout = $totalArchived = 0;

        foreach ($this->groupedLeaseAssets() as $group) {
            $totalAssets += count($group['assets']);
            $totalActive += $group['active'];
            $totalBuyout += $group['buyout'];
            $totalArchived += $group['archived'];

            $records[] = [
                // Buyout-only contracts are dimmed: they're history, not a
                // commitment we still need to manage.
                'class' => $group['active'] === 0 ? 'text-muted' : '',
                'cells' => [
                    $group['contract_id'],
                    (string) $group['contract_name'],
                    $group['provider'],
                    $this->dateString($group['lease_end_date']),
                    (string) $this->fiscalYearFromEndDate($group['lease_end_date']),
                    count($group['assets']),
                    $group['active'],
                    $group['buyout'],
                    $group['archived'],
                    $this->summariseCounts($group['model_counts']),
                    $this->summariseCounts($group['ownership_counts']),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '',
            $totalAssets, $totalActive, $totalBuyout, $totalArchived, '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Lease financial view. For every contract: equipment cost (sum of
     * asset purchase_cost), warranty/soft cost (sum of order-item
     * warranty_cost for the same assets), total, and the distinct PO and
     * CDW order numbers that funded it.
     */
    private function leasesFinancialReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.lease_provider'),
            trans('admin/purchase-orders/general.lease_end_date'),
            trans('admin/purchase-orders/general.lease_fy_ending'),
            trans('admin/purchase-orders/general.lease_assets'),
            trans('admin/purchase-orders/general.lease_equipment_cost'),
            trans('admin/purchase-orders/general.lease_warranty_cost'),
            trans('admin/purchase-orders/general.lease_total_cost'),
            trans('admin/purchase-orders/general.lease_pos'),
            trans('admin/purchase-orders/general.lease_cdw_orders'),
        ];

        $groups = $this->groupedLeaseAssets();

        // Fetch every order item that lines up to a lease asset in one query,
        // keyed by asset id, so the per-contract loop stays O(assets).
        $assetIds = collect($groups)
            ->flatMap(fn ($g) => collect($g['assets'])->pluck('id'))
            ->all();

        $orderItemsByAsset = OrderItem::with('order.purchaseOrder')
            ->where('item_type', Asset::class)
            ->whereIn('item_id', $assetIds)
            ->get()
            ->groupBy('item_id');

        $records = [];
        $totalAssets = 0;
        $totalEquipment = $totalWarranty = $totalCost = 0.0;

        foreach ($groups as $group) {
            $equipmentCost = $group['total_cost'];
            $warrantyCost = 0.0;
            $poNumbers = [];
            $cdwOrders = [];

            foreach ($group['assets'] as $asset) {
                foreach ($orderItemsByAsset->get($asset->id, collect()) as $item) {
                    $warrantyCost += (float) $item->warranty_cost;
                    if ($poNum = $item->order?->purchaseOrder?->po_number) {
                        $poNumbers[$poNum] = true;
                    }
                    if ($orderNum = $item->order?->order_number) {
                        $cdwOrders[$orderNum] = true;
                    }
                }
            }

            $contractTotal = $equipmentCost + $warrantyCost;
            $totalAssets += count($group['assets']);
            $totalEquipment += $equipmentCost;
            $totalWarranty += $warrantyCost;
            $totalCost += $contractTotal;

            $records[] = [
                'class' => '',
                'cells' => [
                    $group['contract_id'],
                    $group['provider'],
                    $this->dateString($group['lease_end_date']),
                    (string) $this->fiscalYearFromEndDate($group['lease_end_date']),
                    count($group['assets']),
                    $this->money($equipmentCost),
                    $this->money($warrantyCost),
                    $this->money($contractTotal),
                    implode(', ', array_keys($poNumbers)),
                    implode(', ', array_keys($cdwOrders)),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '',
            $totalAssets,
            $this->money($totalEquipment),
            $this->money($totalWarranty),
            $this->money($totalCost),
            '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * CSI Schedule Reconciliation. For every 301452-* contract, lists each
     * model as its own line: qty, unit equipment cost, unit warranty cost,
     * line total, plus the distinct POs and CDW orders the model was
     * billed against. Mirrors the per-schedule reconciliation tables in
     * docs/FY2026-27/CSI_Schedule_Reconciliation.md.
     */
    private function csiScheduleReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.forecast_model'),
            trans('admin/purchase-orders/general.lease_qty'),
            trans('admin/purchase-orders/general.lease_unit_equipment'),
            trans('admin/purchase-orders/general.lease_unit_warranty'),
            trans('admin/purchase-orders/general.lease_line_total'),
            trans('admin/purchase-orders/general.lease_pos'),
            trans('admin/purchase-orders/general.lease_cdw_orders'),
            trans('admin/purchase-orders/general.lease_received'),
        ];

        // Restrict to CSI schedules — ECI* contracts have their own
        // Macquarie reconciliation and don't fit the schedule layout.
        $groups = array_filter(
            $this->groupedLeaseAssets(),
            fn ($g) => str_starts_with($g['contract_id'], '301452-')
        );

        $assetIds = collect($groups)
            ->flatMap(fn ($g) => collect($g['assets'])->pluck('id'))
            ->all();

        $orderItemsByAsset = OrderItem::with('order.purchaseOrder')
            ->where('item_type', Asset::class)
            ->whereIn('item_id', $assetIds)
            ->get()
            ->groupBy('item_id');

        $records = [];
        $totalQty = 0;
        $totalLine = 0.0;

        foreach ($groups as $group) {
            // Bucket the assets in this schedule by model name so each
            // line is "Qty × Model" rather than one row per device.
            $byModel = [];
            foreach ($group['assets'] as $asset) {
                $modelName = $asset->model?->name ?: trans('general.na');
                $modelName = html_entity_decode($modelName, ENT_QUOTES | ENT_HTML5);

                if (! isset($byModel[$modelName])) {
                    $byModel[$modelName] = [
                        'qty' => 0,
                        'equipment_total' => 0.0,
                        'warranty_total' => 0.0,
                        'received' => 0,
                        'pos' => [],
                        'orders' => [],
                    ];
                }

                $byModel[$modelName]['qty']++;
                $byModel[$modelName]['equipment_total'] += (float) $asset->purchase_cost;

                foreach ($orderItemsByAsset->get($asset->id, collect()) as $item) {
                    $byModel[$modelName]['warranty_total'] += (float) $item->warranty_cost;
                    if ($poNum = $item->order?->purchaseOrder?->po_number) {
                        $byModel[$modelName]['pos'][$poNum] = true;
                    }
                    if ($orderNum = $item->order?->order_number) {
                        $byModel[$modelName]['orders'][$orderNum] = true;
                    }
                    if ($item->received_at) {
                        $byModel[$modelName]['received']++;
                    }
                }
            }

            ksort($byModel);

            $scheduleQty = 0;
            $scheduleLine = 0.0;

            foreach ($byModel as $modelName => $row) {
                $qty = $row['qty'];
                $unitEquipment = $qty > 0 ? $row['equipment_total'] / $qty : 0.0;
                $unitWarranty = $qty > 0 ? $row['warranty_total'] / $qty : 0.0;
                $line = $row['equipment_total'] + $row['warranty_total'];

                $scheduleQty += $qty;
                $scheduleLine += $line;

                $records[] = [
                    'class' => '',
                    'cells' => [
                        $group['contract_id'],
                        $modelName,
                        $qty,
                        $this->money($unitEquipment),
                        $this->money($unitWarranty),
                        $this->money($line),
                        implode(', ', array_keys($row['pos'])),
                        implode(', ', array_keys($row['orders'])),
                        $row['received'].' / '.$qty,
                    ],
                ];
            }

            // Per-schedule subtotal row so the reader can compare against
            // the CSI Exhibit A totals without doing the maths in their
            // head.
            $records[] = [
                'class' => 'info',
                'cells' => [
                    $group['contract_id'].' '.trans('admin/orders/general.total'),
                    '', $scheduleQty, '', '',
                    $this->money($scheduleLine),
                    '', '', '',
                ],
            ];

            $totalQty += $scheduleQty;
            $totalLine += $scheduleLine;
        }

        $footer = [
            trans('admin/orders/general.total'), '', $totalQty, '', '',
            $this->money($totalLine),
            '', '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Invoice Approval Queue — what AP looks at to answer Mark's monthly
     * "is it OK to pay this?" emails. Each row pairs the CDW invoice
     * total with the expected amount derived from the line items billed
     * on it; the variance is the cents-level signal that something is
     * off. `?status=pending` (the default) shows only the work to do.
     */
    /**
     * Portable replacement for MySQL's `FIELD()` ordering. Emits a `CASE`
     * expression that sorts $column by the position of its value in $values,
     * with anything unmatched sorted last. `FIELD()` does not exist in
     * SQLite, which is one leg of the test matrix; `CASE` runs on both.
     *
     * Returns `[sql, bindings]` ready to spread into `orderByRaw()`.
     */
    private function fieldOrder(string $column, array $values): array
    {
        $cases = '';
        $bindings = [];
        foreach (array_values($values) as $i => $value) {
            $cases .= " when ? then {$i}";
            $bindings[] = $value;
        }

        return ["case {$column}{$cases} else ".count($values).' end', $bindings];
    }

    private function invoiceApprovalReport(?string $statusFilter = null, ?string $attestationFilter = null): array
    {
        $statusFilter = $statusFilter ?: 'pending';

        $columns = [
            trans('admin/purchase-orders/general.attestation_type'),
            trans('admin/purchase-orders/general.po_number'),
            trans('general.order_number'),
            trans('admin/orders/general.invoice_number'),
            trans('admin/orders/general.invoice_date'),
            trans('admin/purchase-orders/general.invoice_vendor_total'),
            trans('admin/purchase-orders/general.invoice_expected'),
            trans('admin/purchase-orders/general.invoice_variance'),
            trans('admin/purchase-orders/general.invoice_usage'),
            trans('admin/purchase-orders/general.invoice_final'),
            trans('admin/purchase-orders/general.invoice_approval_status'),
            trans('admin/purchase-orders/general.invoice_approver'),
        ];

        $query = OrderInvoice::with('order.purchaseOrder', 'items', 'approver')
            ->orderByRaw(...$this->fieldOrder('approval_status', ['pending', 'disputed', 'approved']))
            ->orderBy('invoice_date');

        if ($statusFilter !== 'all') {
            $query->where('approval_status', $statusFilter);
        }

        // Filter on attestation_type so the lessor-OKP queue (Sohee's
        // "reply okay to pay" sign-off) can be opened in its own view
        // without losing the shared schema with vendor invoices.
        if ($attestationFilter && in_array($attestationFilter, OrderInvoice::ATTESTATION_TYPES, true)) {
            $query->where('attestation_type', $attestationFilter);
        }

        $invoices = $query->get();

        $records = [];
        $totalVendor = $totalExpected = $totalVariance = 0.0;

        foreach ($invoices as $invoice) {
            $expected = $invoice->expectedSubtotal();
            $variance = $invoice->variance();
            $totalVendor += (float) $invoice->subtotal;
            $totalExpected += $expected;
            $totalVariance += $variance;

            $records[] = [
                // Variance over a dollar gets the danger class — that's
                // the threshold below which Mark is happy to wave through.
                'class' => abs($variance) > 1.0 && $invoice->isPendingApproval() ? 'danger' : '',
                'cells' => [
                    trans('admin/purchase-orders/general.attestation_'.($invoice->attestation_type ?: 'vendor_invoice')),
                    (string) $invoice->order?->purchaseOrder?->po_number,
                    (string) $invoice->order?->order_number,
                    $invoice->invoice_number,
                    $this->dateString($invoice->invoice_date),
                    $this->money($invoice->subtotal),
                    $this->money($expected),
                    $this->money($variance),
                    (string) $invoice->usage_tag,
                    $invoice->is_final_invoice ? trans('general.yes') : trans('general.no'),
                    trans('admin/purchase-orders/general.invoice_approval_'.($invoice->approval_status ?: 'pending')),
                    (string) $invoice->approver?->full_name,
                ],
            ];
        }

        $footer = [
            '',
            trans('admin/orders/general.total'), '', '', '',
            $this->money($totalVendor),
            $this->money($totalExpected),
            $this->money($totalVariance),
            '', '', '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Faculty Laptop Program Top-Up Ledger. Every faculty agreement
     * — pickup, paid upgrade, or lease-end buyout — appears on one
     * timeline with its lifecycle stage, financial impact and signed-
     * agreement status. Replaces the multi-sheet SharePoint workbook
     * Sohee maintains by hand.
     */
    private function facultyLedgerReport(?string $typeFilter = null, ?string $stageFilter = null): array
    {
        $columns = [
            trans('admin/purchase-orders/general.faculty_agreement_type'),
            trans('admin/purchase-orders/general.faculty_member'),
            trans('admin/purchase-orders/general.detail_asset_tag'),
            trans('admin/purchase-orders/general.detail_serial'),
            trans('admin/purchase-orders/general.faculty_stage'),
            trans('admin/purchase-orders/general.faculty_contract_value'),
            trans('admin/purchase-orders/general.faculty_payment_method'),
            trans('admin/purchase-orders/general.faculty_balance_paid'),
            trans('admin/purchase-orders/general.faculty_balance_remaining'),
            trans('admin/purchase-orders/general.faculty_signed_at'),
            trans('admin/purchase-orders/general.faculty_payroll_at'),
        ];

        $query = FacultyAgreement::with('user', 'asset')
            ->orderByRaw(...$this->fieldOrder('lifecycle_stage', [
                'eligible', 'quoted', 'agreement_sent', 'agreement_signed',
                'deployed', 'in_repayment', 'paid_off', 'closed_buyout', 'closed',
            ]))
            ->orderBy('updated_at', 'desc');

        if ($typeFilter && in_array($typeFilter, FacultyAgreement::AGREEMENT_TYPES, true)) {
            $query->where('agreement_type', $typeFilter);
        }
        if ($stageFilter && in_array($stageFilter, FacultyAgreement::LIFECYCLE_STAGES, true)) {
            $query->where('lifecycle_stage', $stageFilter);
        }

        $agreements = $query->get();

        $records = [];
        $totalValue = $totalPaid = $totalRemaining = 0.0;

        foreach ($agreements as $agreement) {
            $value = $agreement->contractValue();
            $paid = (float) $agreement->balance_paid;
            $remaining = $agreement->balance_remaining !== null
                ? (float) $agreement->balance_remaining
                : max($value - $paid, 0.0);

            $totalValue += $value;
            $totalPaid += $paid;
            $totalRemaining += $remaining;

            // Stage colour cues so Sohee can spot stuck agreements at a
            // glance: signed-but-not-deployed and quoted-but-not-signed
            // are the rows that need chasing.
            $class = match ($agreement->lifecycle_stage) {
                'quoted', 'agreement_sent' => 'warning',
                'agreement_signed' => 'info',
                'paid_off', 'closed_buyout', 'closed' => 'text-muted',
                default => '',
            };

            $records[] = [
                'class' => $class,
                'cells' => [
                    trans('admin/purchase-orders/general.faculty_type_'.$agreement->agreement_type),
                    (string) ($agreement->user?->full_name ?? '—'),
                    (string) ($agreement->asset?->asset_tag ?? ''),
                    (string) ($agreement->asset?->serial ?? ''),
                    trans('admin/purchase-orders/general.faculty_stage_'.$agreement->lifecycle_stage),
                    $this->money($value),
                    $agreement->payment_method
                        ? trans('admin/purchase-orders/general.faculty_payment_'.$agreement->payment_method)
                        : '',
                    $this->money($paid),
                    $this->money($remaining),
                    $this->dateString($agreement->signed_at),
                    $this->dateString($agreement->sent_to_payroll_at),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '',
            $this->money($totalValue),
            '',
            $this->money($totalPaid),
            $this->money($totalRemaining),
            '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Lease Decision Tracker — surfaces the buyout/return/extend/replace
     * decisions logged against expiring leases (the PR #17 table) inside
     * the procurement reports area so finance doesn't have to find the
     * Settings link.
     */
    private function leaseDecisionsReport(?string $statusFilter = null): array
    {
        $columns = [
            trans('admin/lease-decisions/general.contract_reference'),
            trans('admin/lease-decisions/general.decision_type'),
            trans('admin/lease-decisions/general.decision_date'),
            trans('admin/lease-decisions/general.amount'),
            trans('admin/lease-decisions/general.status'),
            trans('general.notes'),
        ];

        $query = LeaseDecision::query()
            ->orderByRaw(...$this->fieldOrder('status', ['pending', 'approved', 'completed', 'cancelled']))
            ->orderBy('decision_date');

        if ($statusFilter && in_array($statusFilter, LeaseDecision::STATUSES, true)) {
            $query->where('status', $statusFilter);
        }

        $decisions = $query->get();

        $records = [];
        $totalAmount = 0.0;

        foreach ($decisions as $decision) {
            $totalAmount += (float) $decision->amount;

            $records[] = [
                'class' => $decision->status === 'pending' ? 'warning' : '',
                'cells' => [
                    $decision->contract_reference,
                    trans('admin/lease-decisions/general.type_'.$decision->decision_type),
                    $this->dateString($decision->decision_date),
                    $this->money($decision->amount),
                    trans('admin/lease-decisions/general.status_'.$decision->status),
                    (string) $decision->notes,
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '',
            $this->money($totalAmount), '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Builds the GL Journal Transfer report: consumable transactions
     * sorted by GL code then date, a subtotal row closing each GL group,
     * and a grand total in the footer.
     */
    private function glJournalTransferReport(?string $fiscalYear = null, ?string $statusFilter = null): array
    {
        $columns = [
            trans('admin/consumables/general.gl_txn_code'),
            trans('admin/consumables/general.gl_txn_date'),
            trans('admin/consumables/general.gl_txn_printer'),
            trans('general.consumable'),
            trans('admin/consumables/general.gl_txn_qty'),
            trans('admin/consumables/general.gl_txn_unit_cost'),
            trans('admin/consumables/general.gl_txn_total'),
            trans('admin/consumables/general.gl_txn_fiscal_year'),
            trans('admin/consumables/general.gl_txn_status'),
        ];

        $query = ConsumableTransaction::with('consumable', 'asset')
            ->orderBy('gl_code')
            ->orderBy('transaction_date');

        if ($fiscalYear) {
            $query->where('fiscal_year', $fiscalYear);
        }
        if (in_array($statusFilter, [
            ConsumableTransaction::STATUS_DRAFT,
            ConsumableTransaction::STATUS_POSTED,
            ConsumableTransaction::STATUS_TRANSFERRED,
        ], true)) {
            $query->where('status', $statusFilter);
        }

        $records = [];
        $grandTotal = 0.0;
        $groupTotal = 0.0;
        $currentGl = null;

        foreach ($query->get() as $txn) {
            if ($currentGl !== null && $txn->gl_code !== $currentGl) {
                $records[] = $this->glSubtotalRow($currentGl, $groupTotal);
                $groupTotal = 0.0;
            }
            $currentGl = $txn->gl_code;

            $lineTotal = (float) $txn->total_cost;
            $groupTotal += $lineTotal;
            $grandTotal += $lineTotal;

            $records[] = [
                'class' => '',
                'cells' => [
                    (string) $txn->gl_code,
                    $this->dateString($txn->transaction_date),
                    $txn->asset?->present()->name() ?? '',
                    $txn->consumable?->name ?? '',
                    (string) $txn->quantity,
                    $this->money($txn->unit_cost),
                    $this->money($txn->total_cost),
                    (string) $txn->fiscal_year,
                    ucfirst((string) $txn->status),
                ],
            ];
        }

        if ($currentGl !== null) {
            $records[] = $this->glSubtotalRow($currentGl, $groupTotal);
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '', '',
            $this->money($grandTotal), '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * A per-GL subtotal row for the GL Journal Transfer report — the
     * amount to journal to that GL code.
     */
    private function glSubtotalRow(string $glCode, float $total): array
    {
        return [
            'class' => 'info',
            'cells' => [
                $glCode,
                trans('admin/purchase-orders/general.gl_transfer_subtotal'),
                '', '', '', '',
                $this->money($total),
                '', '',
            ],
        ];
    }

    /**
     * Year-End PO Disposition. For every purchase order, the over/under
     * vs. budget and a suggested year-end disposition: close the PO,
     * roll the remaining commitment to the next fiscal year, or
     * reallocate the surplus to operating. Replaces the year-end
     * walk-through Rod writes Mark by hand in Excel.
     */
    private function poDispositionReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/purchase-orders/general.cost_center'),
            trans('admin/purchase-orders/general.budget'),
            trans('admin/purchase-orders/general.invoiced'),
            trans('admin/purchase-orders/general.committed'),
            trans('admin/purchase-orders/general.remaining'),
            trans('admin/purchase-orders/general.po_open_orders'),
            trans('admin/purchase-orders/general.po_disposition'),
        ];

        $purchaseOrders = PurchaseOrder::with('orders.items', 'orders.invoices')
            ->orderBy('fiscal_year')
            ->orderBy('po_number')
            ->get();

        $records = [];
        $totalBudget = $totalInvoiced = $totalCommitted = 0.0;

        foreach ($purchaseOrders as $po) {
            $budget = (float) $po->budget;
            $invoiced = $po->invoicedTotal();
            $committed = $po->committedTotal();
            $remaining = $budget - $committed;
            $openOrders = $po->orders->filter(fn ($o) => ! in_array($o->status, ['received', 'cancelled'], true))->count();

            $totalBudget += $budget;
            $totalInvoiced += $invoiced;
            $totalCommitted += $committed;

            $disposition = $this->dispositionFor($po, $remaining, $openOrders);

            $records[] = [
                'class' => $remaining < 0 ? 'danger' : ($openOrders > 0 ? 'warning' : ''),
                'cells' => [
                    $po->po_number,
                    (string) $po->fiscal_year,
                    (string) $po->cost_center,
                    $this->money($budget),
                    $this->money($invoiced),
                    $this->money($committed),
                    $this->money($remaining),
                    $openOrders,
                    $disposition,
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '',
            $this->money($totalBudget),
            $this->money($totalInvoiced),
            $this->money($totalCommitted),
            $this->money($totalBudget - $totalCommitted),
            '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Suggest a year-end disposition for a purchase order. The suggestion
     * is advisory — it's the answer Rod would write Mark on email, not
     * an automated action.
     */
    private function dispositionFor(PurchaseOrder $po, float $remaining, int $openOrders): string
    {
        if ($po->status === 'closed' || $po->status === 'cancelled') {
            return trans('admin/purchase-orders/general.disposition_closed');
        }
        if ($remaining < -1.0) {
            return trans('admin/purchase-orders/general.disposition_overrun');
        }
        if ($openOrders > 0) {
            return trans('admin/purchase-orders/general.disposition_roll');
        }
        if ($remaining > 1.0) {
            return trans('admin/purchase-orders/general.disposition_reallocate');
        }

        return trans('admin/purchase-orders/general.disposition_close');
    }

    /**
     * Extension Watch. Macquarie/CSI leases whose end date has slipped
     * past the original term — these are the "expensive to keep
     * extending" ones Mark flags. Heuristic: any contract whose latest
     * Lease End Date is more than 4 years (rental) or 5 years (lease to
     * own) past the earliest asset purchase date is treated as extended.
     */
    private function extensionWatchReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.lease_provider'),
            trans('admin/purchase-orders/general.extension_original_end'),
            trans('admin/purchase-orders/general.lease_end_date'),
            trans('admin/purchase-orders/general.extension_months'),
            trans('admin/purchase-orders/general.lease_assets'),
            trans('admin/purchase-orders/general.lease_active'),
            trans('admin/purchase-orders/general.extension_monthly_cost'),
        ];

        $records = [];

        foreach ($this->groupedLeaseAssets() as $group) {
            // Use the earliest asset purchase_date in the group as the
            // proxy for the lease start. ECI contracts are 4-year rentals
            // (term = 48 months); 301452 schedules split between 4-year
            // returns and 5-year lease-to-own — see the ownership counts.
            $earliestPurchase = null;
            foreach ($group['assets'] as $asset) {
                if ($asset->purchase_date) {
                    $purchase = $asset->purchase_date instanceof \DateTimeInterface
                        ? $asset->purchase_date
                        : new \DateTime((string) $asset->purchase_date);
                    if ($earliestPurchase === null || $purchase < $earliestPurchase) {
                        $earliestPurchase = $purchase;
                    }
                }
            }

            $leaseEnd = null;
            foreach (['Y-m-d', 'm/d/Y'] as $fmt) {
                if (! empty($group['lease_end_date'])) {
                    $leaseEnd = \DateTime::createFromFormat($fmt, $group['lease_end_date']);
                    if ($leaseEnd !== false) {
                        break;
                    }
                }
            }

            if (! $earliestPurchase || ! $leaseEnd) {
                continue;
            }

            $isLeaseToOwn = ! empty($group['ownership_counts']['Lease to Own']);
            $termMonths = $isLeaseToOwn ? 60 : 48;
            $originalEnd = (clone $earliestPurchase)->modify("+{$termMonths} months");

            $months = (($leaseEnd->format('Y') - $originalEnd->format('Y')) * 12)
                + ((int) $leaseEnd->format('m') - (int) $originalEnd->format('m'));

            if ($months <= 0) {
                continue;
            }

            // Prefer the real Lease Rent sum when available — the fall-back
            // amortises the total contract cost across the original term,
            // which is only an estimate.
            $monthlyCost = $group['monthly_rent_total'] > 0
                ? $group['monthly_rent_total']
                : ($termMonths > 0 ? $group['total_cost'] / $termMonths : 0.0);

            $records[] = [
                'class' => $months > 12 ? 'danger' : 'warning',
                'cells' => [
                    $group['contract_id'],
                    $group['provider'],
                    $originalEnd->format('Y-m-d'),
                    $leaseEnd->format('Y-m-d'),
                    $months,
                    count($group['assets']),
                    $group['active'],
                    $this->money($monthlyCost),
                ],
            ];
        }

        return ['columns' => $columns, 'records' => $records];
    }

    /**
     * Asset Retirement Obligation register. Mark needs to book obligations
     * for known end-of-useful-life costs: buyouts, return fees and disposal.
     * This view aggregates the LeaseDecision log into one finance-ready
     * table, one row per contract+decision-type.
     */
    private function aroRegisterReport(): array
    {
        $columns = [
            trans('admin/lease-decisions/general.contract_reference'),
            trans('admin/purchase-orders/general.aro_source'),
            trans('admin/lease-decisions/general.decision_type'),
            trans('admin/lease-decisions/general.amount'),
            trans('admin/lease-decisions/general.status'),
            trans('admin/lease-decisions/general.decision_date'),
        ];

        $records = [];
        $total = 0.0;

        // Real per-asset Buyout Cost values aggregated per contract —
        // the contractual obligation regardless of whether the buyout has
        // been booked as a LeaseDecision yet. Only shown when the field
        // contains real numbers.
        foreach ($this->groupedLeaseAssets() as $group) {
            if ($group['buyout_cost_total'] <= 0) {
                continue;
            }
            $total += $group['buyout_cost_total'];
            $records[] = [
                'class' => '',
                'cells' => [
                    $group['contract_id'],
                    trans('admin/purchase-orders/general.aro_source_asset'),
                    trans('admin/lease-decisions/general.type_buyout'),
                    $this->money($group['buyout_cost_total']),
                    trans('admin/purchase-orders/general.aro_status_contractual'),
                    '',
                ],
            ];
        }

        // Logged decisions — buyout or return amounts a human has signed
        // off on (or proposed). Cancelled is excluded.
        $decisions = LeaseDecision::query()
            ->whereIn('decision_type', ['buyout', 'return'])
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('contract_reference')
            ->get();

        foreach ($decisions as $decision) {
            $total += (float) $decision->amount;
            $records[] = [
                'class' => $decision->status === 'pending' ? 'warning' : '',
                'cells' => [
                    $decision->contract_reference,
                    trans('admin/purchase-orders/general.aro_source_decision'),
                    trans('admin/lease-decisions/general.type_'.$decision->decision_type),
                    $this->money($decision->amount),
                    trans('admin/lease-decisions/general.status_'.$decision->status),
                    $this->dateString($decision->decision_date),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '',
            $this->money($total), '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Asset Lease Detail — the full per-asset roll-up that the
     * sharepoint.csv export gives. Lives in /reports/procurement so the
     * same data is available internally without having to open the
     * SharePoint workbook. One row per leased asset, with finance,
     * lifecycle and usage columns.
     */
    private function assetLeaseDetailReport(): array
    {
        $cols = $this->leaseFieldColumns();

        $columns = [
            trans('admin/purchase-orders/general.detail_asset_tag'),
            trans('admin/purchase-orders/general.detail_serial'),
            trans('admin/purchase-orders/general.detail_status'),
            trans('admin/purchase-orders/general.detail_model'),
            trans('admin/purchase-orders/general.invoice_usage'),
            trans('admin/purchase-orders/general.detail_area'),
            trans('admin/purchase-orders/general.detail_assigned_to'),
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.lease_end_date'),
            trans('admin/purchase-orders/general.detail_ownership'),
            trans('admin/purchase-orders/general.detail_purchase_cost'),
            trans('admin/purchase-orders/general.detail_lease_rent'),
            trans('admin/purchase-orders/general.detail_buyout_cost'),
            trans('admin/purchase-orders/general.detail_decommission'),
        ];

        $contractIdColumn = $cols['contract_id'];
        if (! $contractIdColumn) {
            return ['columns' => $columns, 'records' => [], 'footer' => null];
        }

        $assets = Asset::with('model', 'status', 'assignedTo')
            ->whereNotNull($contractIdColumn)
            ->where($contractIdColumn, '!=', '')
            ->orderBy($contractIdColumn)
            ->orderBy('asset_tag')
            ->get();

        $records = [];
        $totalPurchase = $totalRent = $totalBuyout = 0.0;

        foreach ($assets as $asset) {
            $contractId = $asset->{$contractIdColumn};
            if (! $this->isValidContractId($contractId)) {
                continue;
            }

            $purchase = (float) $asset->purchase_cost;
            $rent = $cols['lease_rent'] ? $this->parseMoney($asset->{$cols['lease_rent']}) : 0.0;
            $buyout = $cols['buyout_cost'] ? $this->parseMoney($asset->{$cols['buyout_cost']}) : 0.0;

            $totalPurchase += $purchase;
            $totalRent += $rent;
            $totalBuyout += $buyout;

            // Dim assets that have already been returned or are otherwise
            // off the lease so the live fleet stays prominent.
            $isReturned = ! empty($cols['decommission_date']) && ! empty($asset->{$cols['decommission_date']});

            $records[] = [
                'class' => $isReturned ? 'text-muted' : '',
                'cells' => [
                    (string) $asset->asset_tag,
                    (string) $asset->serial,
                    (string) $asset->status?->name,
                    (string) $asset->model?->name,
                    $cols['usage'] ? (string) $asset->{$cols['usage']} : '',
                    $cols['area'] ? (string) $asset->{$cols['area']} : '',
                    (string) $this->describeAssignedTo($asset->assignedTo),
                    $contractId,
                    $cols['lease_end_date'] ? $this->dateString($asset->{$cols['lease_end_date']}) : '',
                    $cols['ownership_type'] ? (string) $asset->{$cols['ownership_type']} : '',
                    $this->money($purchase),
                    $rent > 0 ? $this->money($rent) : '',
                    $buyout > 0 ? $this->money($buyout) : '',
                    $cols['decommission_date'] ? $this->dateString($asset->{$cols['decommission_date']}) : '',
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '', '', '', '', '', '',
            $this->money($totalPurchase),
            $this->money($totalRent),
            $this->money($totalBuyout),
            '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * PO ↔ CDW drill-down. Per-PO walk of every CDW order under it, every
     * invoice billed against those orders, and the variance between invoice
     * subtotal and expected line-item total. Subtotal rows mark the PO
     * boundary so a finance reader can scan top-to-bottom and see exactly
     * what each PO funded.
     */
    private function poDrilldownReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.po_number'),
            trans('general.order_number'),
            trans('admin/orders/general.invoice_number'),
            trans('admin/orders/general.invoice_date'),
            trans('admin/orders/general.line_items'),
            trans('admin/purchase-orders/general.invoice_vendor_total'),
            trans('admin/purchase-orders/general.invoice_expected'),
            trans('admin/purchase-orders/general.invoice_variance'),
            trans('admin/purchase-orders/general.invoice_approval_status'),
        ];

        $purchaseOrders = PurchaseOrder::with('orders.invoices.items', 'orders.items')
            ->orderBy('po_number')
            ->get();

        $records = [];
        $grandVendor = $grandExpected = $grandVariance = 0.0;

        foreach ($purchaseOrders as $po) {
            $poVendor = $poExpected = $poVariance = 0.0;
            $poRows = [];

            foreach ($po->orders as $order) {
                if ($order->invoices->isEmpty()) {
                    $expectedFromItems = (float) $order->items->sum->lineTotal();
                    $poExpected += $expectedFromItems;
                    $poRows[] = [
                        'class' => 'warning',
                        'cells' => [
                            $po->po_number,
                            (string) $order->order_number,
                            trans('admin/purchase-orders/general.po_drilldown_no_invoice'),
                            '',
                            $order->items->count(),
                            '',
                            $this->money($expectedFromItems),
                            '',
                            '',
                        ],
                    ];

                    continue;
                }

                foreach ($order->invoices as $invoice) {
                    $expected = $invoice->expectedSubtotal();
                    $variance = $invoice->variance();
                    $vendor = (float) $invoice->subtotal;

                    $poVendor += $vendor;
                    $poExpected += $expected;
                    $poVariance += $variance;

                    $poRows[] = [
                        'class' => abs($variance) > 1.0 ? 'danger' : '',
                        'cells' => [
                            $po->po_number,
                            (string) $order->order_number,
                            $invoice->invoice_number,
                            $this->dateString($invoice->invoice_date),
                            $invoice->items->count(),
                            $this->money($vendor),
                            $this->money($expected),
                            $this->money($variance),
                            trans('admin/purchase-orders/general.invoice_approval_'.($invoice->approval_status ?: 'pending')),
                        ],
                    ];
                }
            }

            $grandVendor += $poVendor;
            $grandExpected += $poExpected;
            $grandVariance += $poVariance;

            $records = array_merge($records, $poRows);

            // PO subtotal row so the eye can find boundaries quickly.
            $records[] = [
                'class' => 'info',
                'cells' => [
                    $po->po_number.' '.trans('admin/orders/general.total'),
                    '', '', '', '',
                    $this->money($poVendor),
                    $this->money($poExpected),
                    $this->money($poVariance),
                    '',
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '',
            $this->money($grandVendor),
            $this->money($grandExpected),
            $this->money($grandVariance),
            '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Per-Serial Disposition Grid. One row per leased asset, grouped by
     * contract, showing the latest LeaseDecision action (if any) against
     * that asset's contract reference. Sohee's ledger for confirming
     * buyout/return/extend decisions per serial without an email back-
     * and-forth.
     */
    private function dispositionGridReport(): array
    {
        $cols = $this->leaseFieldColumns();

        $columns = [
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.detail_asset_tag'),
            trans('admin/purchase-orders/general.detail_serial'),
            trans('admin/purchase-orders/general.detail_model'),
            trans('admin/purchase-orders/general.detail_status'),
            trans('admin/purchase-orders/general.invoice_usage'),
            trans('admin/purchase-orders/general.detail_buyout_cost'),
            trans('admin/purchase-orders/general.disposition_action'),
            trans('admin/lease-decisions/general.status'),
            trans('admin/purchase-orders/general.disposition_decided_on'),
        ];

        $contractIdColumn = $cols['contract_id'];
        if (! $contractIdColumn) {
            return ['columns' => $columns, 'records' => [], 'footer' => null];
        }

        // Latest decision per contract_reference — Sohee logs one entry
        // per buyout/return/extend event but a contract can collect many
        // over time. The latest wins.
        $latestDecisions = LeaseDecision::query()
            ->orderBy('contract_reference')
            ->orderByDesc('decision_date')
            ->get()
            ->groupBy('contract_reference')
            ->map(fn ($group) => $group->first());

        $assets = Asset::with('model', 'status')
            ->whereNotNull($contractIdColumn)
            ->where($contractIdColumn, '!=', '')
            ->orderBy($contractIdColumn)
            ->orderBy('asset_tag')
            ->get();

        $records = [];
        foreach ($assets as $asset) {
            $contractId = $asset->{$contractIdColumn};
            if (! $this->isValidContractId($contractId)) {
                continue;
            }

            $decision = $latestDecisions->get($contractId);
            $buyoutCost = $cols['buyout_cost'] ? $this->parseMoney($asset->{$cols['buyout_cost']}) : 0.0;

            $records[] = [
                'class' => $decision && $decision->status === 'pending' ? 'warning' : '',
                'cells' => [
                    $contractId,
                    (string) $asset->asset_tag,
                    (string) $asset->serial,
                    (string) $asset->model?->name,
                    (string) $asset->status?->name,
                    $cols['usage'] ? (string) $asset->{$cols['usage']} : '',
                    $buyoutCost > 0 ? $this->money($buyoutCost) : '',
                    $decision ? trans('admin/lease-decisions/general.type_'.$decision->decision_type) : trans('admin/purchase-orders/general.disposition_none'),
                    $decision ? trans('admin/lease-decisions/general.status_'.$decision->status) : '',
                    $decision ? $this->dateString($decision->decision_date) : '',
                ],
            ];
        }

        return ['columns' => $columns, 'records' => $records];
    }

    /**
     * Credit & Termination Ledger. The lease invoice stream is not just
     * monthly rent — every contract eventually accumulates credit memos
     * and a final termination invoice. Splitting them out lets finance
     * see how much credit is outstanding and confirm the closing
     * termination matches the schedule.
     */
    private function creditTerminationReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/orders/general.invoice_number'),
            trans('admin/purchase-orders/general.credit_invoice_type'),
            trans('admin/orders/general.invoice_date'),
            trans('admin/orders/general.subtotal'),
            trans('admin/orders/general.tax_gst'),
            trans('admin/orders/general.tax_pst'),
            trans('admin/orders/general.total'),
            trans('admin/purchase-orders/general.invoice_approval_status'),
        ];

        $invoices = OrderInvoice::with('order.purchaseOrder')
            ->whereIn('invoice_type', ['credit', 'termination', 'buyout'])
            ->orderBy('contract_reference')
            ->orderBy('invoice_date')
            ->get();

        $records = [];
        $totalSubtotal = $totalTotal = 0.0;

        foreach ($invoices as $invoice) {
            $totalSubtotal += (float) $invoice->subtotal;
            $totalTotal += (float) $invoice->total;

            $records[] = [
                'class' => $invoice->invoice_type === 'credit' ? 'success' : ($invoice->invoice_type === 'termination' ? 'info' : ''),
                'cells' => [
                    (string) $invoice->contract_reference,
                    $invoice->invoice_number,
                    trans('admin/purchase-orders/general.invoice_type_'.$invoice->invoice_type),
                    $this->dateString($invoice->invoice_date),
                    $this->money($invoice->subtotal),
                    $this->money($invoice->tax_gst),
                    $this->money($invoice->tax_pst),
                    $this->money($invoice->total),
                    trans('admin/purchase-orders/general.invoice_approval_'.($invoice->approval_status ?: 'pending')),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '',
            $this->money($totalSubtotal), '', '',
            $this->money($totalTotal), '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Lessor / Vendor breakdown. Mirrors the TDX provider mapping in the
     * sync function: CSI Leasing (301452-*) and Macquarie / CCA Financial
     * (ECI*). The CCA naming reflects Macquarie's mid-2025 portfolio sale
     * to CCA Financial — same ECI contract IDs, new lessor.
     */
    private function lessorBreakdownReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.lease_provider'),
            trans('admin/purchase-orders/general.lessor_contracts'),
            trans('admin/purchase-orders/general.lease_assets'),
            trans('admin/purchase-orders/general.lease_active'),
            trans('admin/purchase-orders/general.lease_buyouts'),
            trans('admin/purchase-orders/general.lease_total_cost'),
            trans('admin/purchase-orders/general.extension_monthly_cost'),
            trans('admin/purchase-orders/general.lessor_ownership_mix'),
        ];

        $byLessor = [];
        foreach ($this->groupedLeaseAssets() as $group) {
            $key = $group['provider'];
            if (! isset($byLessor[$key])) {
                $byLessor[$key] = [
                    'contracts' => 0,
                    'assets' => 0,
                    'active' => 0,
                    'buyout' => 0,
                    'cost' => 0.0,
                    'rent' => 0.0,
                    'ownership' => [],
                ];
            }
            $byLessor[$key]['contracts']++;
            $byLessor[$key]['assets'] += count($group['assets']);
            $byLessor[$key]['active'] += $group['active'];
            $byLessor[$key]['buyout'] += $group['buyout'];
            $byLessor[$key]['cost'] += $group['total_cost'];
            $byLessor[$key]['rent'] += $group['monthly_rent_total'];
            foreach ($group['ownership_counts'] as $type => $count) {
                $byLessor[$key]['ownership'][$type] = ($byLessor[$key]['ownership'][$type] ?? 0) + $count;
            }
        }

        ksort($byLessor);

        $records = [];
        $totalContracts = $totalAssets = $totalActive = $totalBuyout = 0;
        $totalCost = $totalRent = 0.0;

        foreach ($byLessor as $lessor => $data) {
            $totalContracts += $data['contracts'];
            $totalAssets += $data['assets'];
            $totalActive += $data['active'];
            $totalBuyout += $data['buyout'];
            $totalCost += $data['cost'];
            $totalRent += $data['rent'];

            $records[] = [
                'class' => '',
                'cells' => [
                    $lessor,
                    $data['contracts'],
                    $data['assets'],
                    $data['active'],
                    $data['buyout'],
                    $this->money($data['cost']),
                    $this->money($data['rent']),
                    $this->summariseCounts($data['ownership']),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'),
            $totalContracts, $totalAssets, $totalActive, $totalBuyout,
            $this->money($totalCost),
            $this->money($totalRent),
            '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * PST Applicability. Curriculum-tagged assets are PST-exempt under
     * BC's school-supplies exemption; Admin-tagged assets are not. Per
     * contract: split the dollar value between exempt and taxable and
     * compute the estimated PST exposure (7% of the taxable share).
     */
    private function pstApplicabilityReport(): array
    {
        $cols = $this->leaseFieldColumns();
        $pstRate = 0.07;

        $columns = [
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.lease_assets'),
            trans('admin/purchase-orders/general.pst_curriculum_share'),
            trans('admin/purchase-orders/general.pst_admin_share'),
            trans('admin/purchase-orders/general.pst_exempt_value'),
            trans('admin/purchase-orders/general.pst_taxable_value'),
            trans('admin/purchase-orders/general.pst_estimated_pst'),
        ];

        $records = [];
        $totalExempt = $totalTaxable = $totalPst = 0.0;

        foreach ($this->groupedLeaseAssets() as $group) {
            $exemptCost = $taxableCost = 0.0;
            $curriculumCount = $adminCount = 0;

            foreach ($group['assets'] as $asset) {
                $cost = (float) $asset->purchase_cost;
                $usage = $cols['usage'] ? (string) $asset->{$cols['usage']} : '';

                if (stripos($usage, 'curriculum') !== false) {
                    $exemptCost += $cost;
                    $curriculumCount++;
                } elseif (stripos($usage, 'admin') !== false) {
                    $taxableCost += $cost;
                    $adminCount++;
                } else {
                    // No usage tag — treat as taxable for the worst-case
                    // PST exposure so finance doesn't under-budget.
                    $taxableCost += $cost;
                }
            }

            $estPst = $taxableCost * $pstRate;
            $totalExempt += $exemptCost;
            $totalTaxable += $taxableCost;
            $totalPst += $estPst;

            $records[] = [
                'class' => '',
                'cells' => [
                    $group['contract_id'],
                    count($group['assets']),
                    $curriculumCount,
                    $adminCount,
                    $this->money($exemptCost),
                    $this->money($taxableCost),
                    $this->money($estPst),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '',
            $this->money($totalExempt),
            $this->money($totalTaxable),
            $this->money($totalPst),
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Asset `assigned_to` is morphTo so the target can be a User, Asset,
     * or Location. Each surfaces its identifier under a different name —
     * return the most-meaningful one for whichever flavour came back.
     */
    private function describeAssignedTo($target): string
    {
        if ($target === null) {
            return '';
        }
        if ($target instanceof User) {
            return (string) $target->full_name;
        }
        if ($target instanceof Asset) {
            return (string) $target->asset_tag;
        }

        return (string) ($target->name ?? '');
    }

    /**
     * Custom-field money columns are stored as text on the assets table —
     * users type "1,234.56" or "$1,234.56" — so coerce them to floats
     * defensively. Returns 0.0 on empty / unparseable input.
     */
    private function parseMoney($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $cleaned === '' ? 0.0 : (float) $cleaned;
    }

    /**
     * Schedule Signing Queue. The chase view Sohee uses when she needs to
     * know which lease schedules are still draft / awaiting Viktor +
     * Mark's signature. Default filter is the open stages; `?stage=all`
     * exposes signed / active history too. Each row shows the days
     * pending (so old schedules float to the top) and the vendor-on-hold
     * flag (Apple account on hold pattern).
     */
    private function scheduleSigningQueueReport(?string $stageFilter = null): array
    {
        $columns = [
            trans('admin/purchase-orders/general.schedule_ref'),
            trans('admin/purchase-orders/general.lease_provider'),
            trans('admin/purchase-orders/general.schedule_type_term'),
            trans('admin/purchase-orders/general.schedule_received'),
            trans('admin/purchase-orders/general.schedule_stage'),
            trans('admin/purchase-orders/general.schedule_days_pending'),
            trans('admin/purchase-orders/general.schedule_vendor_hold'),
            trans('admin/purchase-orders/general.schedule_expected_cost'),
            trans('admin/purchase-orders/general.schedule_expected_assets'),
            trans('admin/purchase-orders/general.schedule_received_assets'),
            trans('admin/purchase-orders/general.invoice_usage'),
        ];

        $query = LeaseSchedule::query()
            ->orderByRaw(...$this->fieldOrder('lifecycle_stage', [
                'draft', 'awaiting_signature', 'signed', 'active', 'cancelled',
            ]))
            ->orderBy('received_at');

        if ($stageFilter === null || $stageFilter === 'open') {
            $query->whereIn('lifecycle_stage', LeaseSchedule::OPEN_STAGES);
        } elseif ($stageFilter !== 'all' && in_array($stageFilter, LeaseSchedule::LIFECYCLE_STAGES, true)) {
            $query->where('lifecycle_stage', $stageFilter);
        }

        $schedules = $query->get();

        // Real-asset counts per schedule_ref via the existing Lease
        // Contract ID custom field — gives Sohee a quick "Annexure says
        // 18, we received 14" signal. The full Annexure A diff lives in
        // a separate report.
        $contractIdColumn = $this->leaseFieldColumns()['contract_id'] ?? null;
        $assetCounts = [];
        if ($contractIdColumn && $schedules->isNotEmpty()) {
            $refs = $schedules->pluck('schedule_ref')->all();
            $assetCounts = Asset::query()
                ->whereIn($contractIdColumn, $refs)
                ->selectRaw("$contractIdColumn as ref, COUNT(*) as total")
                ->groupBy($contractIdColumn)
                ->pluck('total', 'ref')
                ->all();
        }

        $records = [];
        $openCount = 0;
        $heldCount = 0;

        foreach ($schedules as $schedule) {
            $days = $schedule->daysPending();
            $receivedAssets = $assetCounts[$schedule->schedule_ref] ?? 0;

            // > 10 working days on the chase list is the threshold Sohee
            // flagged in email — over that it likely means the Apple
            // account is sitting blocked. Vendor-on-hold gets the
            // strongest cue regardless of age.
            $class = $schedule->vendor_on_hold
                ? 'danger'
                : ($schedule->isOpen() && $days > 10 ? 'warning' : '');

            if ($schedule->isOpen()) {
                $openCount++;
            }
            if ($schedule->vendor_on_hold) {
                $heldCount++;
            }

            $records[] = [
                'class' => $class,
                'cells' => [
                    $schedule->schedule_ref,
                    (string) $schedule->lessor,
                    trim(($schedule->lease_type ?? '').($schedule->term_months ? ' / '.$schedule->term_months.'mo' : '')),
                    $this->dateString($schedule->received_at),
                    trans('admin/purchase-orders/general.schedule_stage_'.$schedule->lifecycle_stage),
                    $schedule->isOpen() ? $days : '',
                    $schedule->vendor_on_hold ? trans('general.yes') : trans('general.no'),
                    $this->money($schedule->expected_acquisition_cost),
                    (int) ($schedule->expected_asset_count ?? 0),
                    $receivedAssets,
                    (string) $schedule->usage_tag,
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '',
            '',
            $openCount, $heldCount,
            '', '', '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Render a "(N) Item" summary for a count-keyed map. Most-common first,
     * ties broken alphabetically so the output is stable run-to-run.
     */
    private function summariseCounts(array $counts): string
    {
        if (empty($counts)) {
            return '';
        }

        $entries = array_map(
            fn ($name, $count) => ['name' => $name, 'count' => $count],
            array_keys($counts),
            array_values($counts)
        );

        usort($entries, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        return implode(', ', array_map(fn ($e) => '('.$e['count'].') '.$e['name'], $entries));
    }

    /**
     * Render a report as a live page, or stream it as CSV when
     * ?format=csv is requested.
     */
    private function render(Request $request, string $filename, string $title, string $routeName, array $report, string $controls = '', array $extraParams = [])
    {
        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv($filename, $report);
        }

        return view('reports/procurement/show', [
            'reportTitle' => $title,
            'columns' => $report['columns'],
            'rows' => $report['records'],
            'footer' => $report['footer'] ?? null,
            'controls' => $controls,
            'downloadUrl' => route($routeName, array_merge(['format' => 'csv'], $extraParams)),
        ]);
    }

    /**
     * Stream a report array as a downloadable CSV with a UTF-8 BOM and
     * formula escaping.
     */
    private function streamReportCsv(string $filename, array $report): StreamedResponse
    {
        return new StreamedResponse(function () use ($report) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            $formatter = new EscapeFormula('`');

            fputcsv($handle, $report['columns']);

            foreach ($report['records'] as $record) {
                fputcsv($handle, $formatter->escapeRecord($record['cells']));
            }

            if (! empty($report['footer'])) {
                fputcsv($handle, $formatter->escapeRecord($report['footer']));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'-'.date('Y-m-d').'.csv"',
        ]);
    }

    /**
     * Format a numeric value as accounting-style currency for a cell:
     * a dollar sign, thousands separators, two decimals, and negatives
     * in parentheses. Null becomes an empty string.
     */
    private function money($value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (float) $value;
        $formatted = '$'.number_format(abs($value), 2);

        return $value < 0 ? '('.$formatted.')' : $formatted;
    }

    /**
     * Format a date value for a cell. Snipe casts some asset date columns
     * to Carbon and leaves others as plain strings, so handle both.
     */
    private function dateString($value): string
    {
        if (empty($value)) {
            return '';
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }

    /**
     * Persist the current user's hidden-report preferences for the
     * procurement reports list. Body: `{hidden: [report_key, ...]}` —
     * the full list, not a delta. Returns the saved list as JSON.
     */
    public function updateVisibility(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hidden' => 'nullable|array',
            'hidden.*' => 'string|max:191',
        ]);

        $user = $request->user();
        $user->hidden_procurement_reports = array_values(array_unique($validated['hidden'] ?? []));
        $user->save();

        return response()->json(['hidden' => $user->hidden_procurement_reports]);
    }
}
