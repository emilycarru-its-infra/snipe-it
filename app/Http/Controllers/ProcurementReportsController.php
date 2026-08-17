<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\BudgetAllocation;
use App\Models\CapitalRequestLine;
use App\Models\Category;
use App\Models\Contract;
use App\Models\DeploymentItem;
use App\Models\LeaseDecision;
use App\Models\LeaseSchedule;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Statuslabel;
use App\Models\StoreApprover;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\AssetCommitted;
use App\Services\BudgetCarry;
use App\Services\CsiReconciliation;
use App\Services\Leasing\LeaseClosure;
use App\Services\LegacyFleet;
use App\Services\ProcurementPipeline;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use League\Csv\EscapeFormula;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
     * How far ahead of its end date a lease joins the Extension Watch. A
     * schedule inside this window needs a renew/return/buy decision now, so it
     * belongs on the watchlist before it lapses into holdover.
     */
    /**
     * The status marking a device as funded for replacement this fiscal year,
     * as opposed to "Active (Legacy)", which marks one that wants replacing but
     * has no plan or money behind it.
     */
    private const EXTENSION_LOOKAHEAD_MONTHS = 3;

    /**
     * How long after its end date a lease stays on the Extension Watch. Past
     * this the holdover is no longer a live negotiation and any device still
     * showing open is a records gap for Lease Data Health to carry instead.
     */
    private const EXTENSION_LOOKBACK_MONTHS = 6;

    /**
     * Procurement dashboard: budget/spend summary cards, charts and links
     * to the individual reports.
     */
    public function index(Request $request)
    {
        $this->authorize('procurement.view');

        // Fiscal years available across purchase orders and orders, plus the
        // resolved selection. `?fiscal_year=all` opts out; no value defaults
        // to the current FY when it has data. The dashboard appends this FY
        // to its report links so the scope follows the reader through (the
        // reports themselves default to all-years on a direct visit).
        $allFiscalYears = $this->availableFiscalYears();
        $selectedFy = $this->resolveFiscalYear($request);

        // First visit with no chosen scope: open the dashboard on the most
        // recent fiscal year that actually holds committed spend (not the
        // calendar-current year, which is empty early in a fiscal year), and
        // persist it so every inline report and sub-report follows the same
        // global scope. An explicit `?fiscal_year=all` opts out.
        if ($selectedFy === null
            && $request->query('fiscal_year') === null
            && ! $request->session()->has('procurement.fiscal_year')) {
            $selectedFy = $this->defaultFiscalYear($allFiscalYears);
            if ($selectedFy !== null) {
                $request->session()->put('procurement.fiscal_year', $selectedFy);
            }
        }

        $purchaseOrders = PurchaseOrder::when($selectedFy, fn ($query) => $query->where('fiscal_year', $selectedFy))
            ->orderBy('po_number')
            ->get();

        $poRows = [];
        $totalCommitted = 0.0;
        $committedByFy = [];

        // Invoiced is invoice-centric, not PO-centric: an invoice counts in
        // the FY of its booking order (falling back to its invoice_date),
        // whether or not it is linked to a budgeted purchase order. Summing
        // per-PO invoicedTotal() would silently drop the CDW lease invoices
        // that carry no PO.
        $totalInvoiced = (float) $this->scopeInvoiceToFiscalYear(OrderInvoice::query(), $selectedFy)->sum('total');

        // Committed is sourced from the asset records (equipment + warranty),
        // not the order-item import — see assetCommittedByPo().
        $assetCommitted = $this->assetCommittedByPo($selectedFy);

        foreach ($purchaseOrders as $po) {
            $budget = (float) $po->budget;
            $committed = $assetCommitted[$po->po_number] ?? 0.0;

            $totalCommitted += $committed;

            $poRows[] = [
                'po_number' => $po->po_number,
                'budget' => $budget,
                'committed' => $committed,
            ];

            $fy = $po->fiscal_year ?: '—';
            $committedByFy[$fy] = ($committedByFy[$fy] ?? 0) + $committed;
        }

        // Orphan POs — university (P00…) purchase orders that the fleet has
        // been received against (assets carry the PO + cost) but which have
        // no row in the purchase_orders ledger, so the loop above never sees
        // them. Their spend is real and must count toward Committed /
        // Remaining (e.g. P0025747, P0025807), otherwise the cards under-read
        // the committed total. assetCommittedByPo() is already scoped to the
        // selected FY by purchase_date, so any leftover key belongs to it;
        // they carry no budget envelope (budget 0), which is also why they
        // don't feed the per-PO carry-forward.
        $ledgerPoNumbers = $purchaseOrders->pluck('po_number')->all();
        foreach ($assetCommitted as $poNumber => $committed) {
            if (in_array($poNumber, $ledgerPoNumbers, true)) {
                continue;
            }

            $totalCommitted += $committed;
            $poRows[] = [
                'po_number' => $poNumber,
                'budget' => 0.0,
                'committed' => $committed,
            ];

            $fy = $selectedFy ?: '—';
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

        // Fall back to the sum of per-PO budgets when nothing has been booked
        // into the allocation ledger yet, so Approved Budget, Remaining and the
        // utilisation donut render against a real figure instead of $0 (which
        // otherwise drives Remaining to a misleading large negative).
        $budgetFromAllocations = $totalBudget > 0.0;
        if (! $budgetFromAllocations) {
            $totalBudget = (float) $purchaseOrders->sum(fn ($po) => (float) $po->budget);
        }

        // The prior year's unused PO budget joins the pot LIVE — computed
        // from last year's POs and asset-committed at render time, so it
        // tracks the committed data as it's corrected (no posted snapshot
        // to delete and re-post). A manually posted carry_forward
        // allocation overrides it; the all-years view skips it (a carry is
        // an intra-year transfer — it would double-count the PO budgets).
        $liveCarry = null;
        if ($selectedFy && ! $allocations->contains(fn ($a) => $a->source === 'carry_forward')) {
            $liveCarry = BudgetCarry::intoFy($selectedFy);
            if ($liveCarry) {
                $totalBudget += $liveCarry['unused'];
            }
        }

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
        $monthly = $this->scopeInvoiceToFiscalYear(
            OrderInvoice::whereNotNull('invoice_date'),
            $selectedFy
        )
            ->orderBy('invoice_date')
            ->get()
            ->groupBy(fn ($invoice) => $invoice->invoice_date->format('Y-m'))
            ->map(fn ($group) => (float) $group->sum('total'));

        // Assets reaching end-of-life within the next year.
        $eolAssets = Asset::with('model.refreshCatalogItem')->whereNotNull('asset_eol_date')
            ->whereBetween('asset_eol_date', [now()->startOfDay(), now()->addYear()])
            ->get();

        // Lease-end pre-approval — every schedule ending in an FY drives
        // that FY's replacement budget: the lease's full original value was
        // pre-approved at signing and rolls forward whatever the renewal
        // decision is (CSI/CCA Financial schedules are pre-approved). The
        // selected-FY card surfaces this; the FY chart overlays it on
        // committed/planned. A logged buyout / return / extension decision
        // re-assesses what we buy (types/quantities), not whether the
        // budget is approved — so it stays in the estimate, annotated with
        // the call in the breakdown table below the tiles.
        $allLeaseEndSchedules = $this->leaseEndSchedules();
        $leaseExpiryByFy = $this->leaseExpiryByFy($allLeaseEndSchedules);
        $leaseEndSchedules = $selectedFy
            ? array_values(array_filter($allLeaseEndSchedules, fn ($s) => $s['fiscal_year'] === $selectedFy))
            : $allLeaseEndSchedules;
        $leaseExpiryTotal = $selectedFy
            ? (float) ($leaseExpiryByFy[$selectedFy]['cost'] ?? 0.0)
            : (float) array_sum(array_column($leaseExpiryByFy, 'cost'));
        $leaseExpiryCount = $selectedFy
            ? (int) ($leaseExpiryByFy[$selectedFy]['count'] ?? 0)
            : (int) array_sum(array_column($leaseExpiryByFy, 'count'));

        // The lease-end pre-approval joins the approved pot automatically:
        // every schedule ending in the FY was funded at signing, so the new
        // year's budget starts from the replacement estimate plus any carry —
        // no manual allocation needed. A posted 'lease_preapproval'
        // allocation overrides the live figure (same pattern as
        // carry_forward), so a finance-adjusted number wins over the derived
        // one.
        if (! $allocations->contains(fn ($a) => $a->source === 'lease_preapproval')) {
            $totalBudget += $leaseExpiryTotal;
        }

        $fiscalYears = array_keys($committedByFy + $plannedByFy + $leaseExpiryByFy);
        sort($fiscalYears);

        // Finance triage counters — what the dashboard reader sees first.
        // Pending-approval invoices answer Mark's monthly "can I pay
        // this?" question; pending lease decisions catch buyout/return
        // calls that haven't been logged yet.
        $pendingApprovalCount = $this->scopeInvoiceToFiscalYear(
            OrderInvoice::where('approval_status', 'pending'),
            $selectedFy
        )->count();

        $pendingDecisionCount = LeaseDecision::whereNull('asset_id')
            ->whereNotNull('decision_type')
            ->where('status', 'pending')
            ->count();

        // User agreements waiting for a signature — the assets team's chase
        // list. Stuck in 'quoted' or 'agreement_sent' is the failure
        // mode that holds up the Apple account on a pending pickup.
        $userAgreementsAwaitingSignatureCount = UserAgreement::whereIn(
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
            'pipeline' => ProcurementPipeline::build($selectedFy),
            'legacyFleet' => LegacyFleet::summary(),
            'approvers' => StoreApprover::with('user')->get(),
            'pendingApprovalCount' => $pendingApprovalCount,
            'pendingDecisionCount' => $pendingDecisionCount,
            'userAgreementsAwaitingSignatureCount' => $userAgreementsAwaitingSignatureCount,
            'scheduleSigningQueueCount' => $scheduleSigningQueueCount,
            'allFiscalYears' => $allFiscalYears,
            'selectedFy' => $selectedFy,
            'totalBudget' => $totalBudget,
            'budgetFromAllocations' => $budgetFromAllocations,
            'liveCarry' => $liveCarry,
            'totalCommitted' => $totalCommitted,
            'totalInvoiced' => $totalInvoiced,
            'totalRemaining' => $totalBudget - $totalCommitted,
            'plannedTotal' => $plannedTotal,
            'poCount' => $purchaseOrders->count(),
            'orderCount' => Order::actual()
                ->when($selectedFy, fn ($query) => $query->where('fiscal_year', $selectedFy))
                ->count(),
            'invoiceCount' => $this->scopeInvoiceToFiscalYear(OrderInvoice::query(), $selectedFy)->count(),
            'eolCount' => $eolAssets->count(),
            'eolEstimate' => (float) $eolAssets->sum(
                fn ($asset) => $asset->replacementCostEstimate() ?? (float) $asset->purchase_cost
            ),
            'leaseExpiryTotal' => $leaseExpiryTotal,
            'leaseExpiryCount' => $leaseExpiryCount,
            'leaseEndSchedules' => $leaseEndSchedules,
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
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'po-budget-report',
            trans('admin/purchase-orders/general.report_po_budget'),
            'reports.procurement.po-budget',
            $this->poBudgetReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function invoices(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'invoice-reconciliation-report',
            trans('admin/purchase-orders/general.report_invoices'),
            'reports.procurement.invoices',
            $this->invoicesReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function capital(Request $request)
    {
        $this->authorize('procurement.view');

        $forecast = $request->query('mode') === 'forecast';
        $fy = $this->resolveFiscalYear($request);

        return $this->render(
            $request,
            'capital-spend-report',
            trans('admin/purchase-orders/general.report_capital'),
            'reports.procurement.capital',
            $this->capitalReport($forecast, $fy),
            $this->capitalModeToggle($forecast, $request->query('fiscal_year', $fy)),
            ['mode' => $forecast ? 'forecast' : 'actual'],
            true
        );
    }

    /**
     * The forecast lives on one page now — the deployments planning hub,
     * which carries the criteria, the estimates, the CSV and both actions
     * (add to wave, create planned order). This address survives as a door.
     */
    public function refreshForecast(Request $request)
    {
        $this->authorize('procurement.view');

        return redirect()->route('deployments.planning', array_filter([
            'fiscal_year' => $request->query('fiscal_year'),
            'criteria' => $request->query('criteria'),
            'format' => $request->query('format'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []));
    }

    /**
     * Generate a planned order from devices selected on the Refresh
     * Forecast report. Each selected end-of-life asset becomes a planned
     * line item carrying its replacement-cost estimate.
     */
    public function createPlannedOrder(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        // The merged forecast page submits its wave-selection checkboxes
        // (asset_ids); the old field name stays accepted.
        if (! $request->has('assets') && $request->has('asset_ids')) {
            $request->merge(['assets' => $request->input('asset_ids')]);
        }

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

        $assets = Asset::with('model.refreshCatalogItem')
            ->whereIn('id', $validated['assets'])
            ->whereNotIn('id', $alreadyPlanned)
            ->get();

        if ($assets->isEmpty()) {
            return redirect()->route('deployments.planning', array_filter(['fiscal_year' => $validated['fiscal_year'] ?? null]))
                ->with('error', trans('admin/purchase-orders/general.forecast_none_selected'));
        }

        $order = new Order;
        $order->order_number = $validated['order_number'];
        $order->status = 'ordered';
        $order->is_planned = true;
        $order->fiscal_year = $validated['fiscal_year'] ?? null;
        $order->created_by = auth()->id();

        if (! $order->save()) {
            return redirect()->route('deployments.planning', array_filter(['fiscal_year' => $validated['fiscal_year'] ?? null]))
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
                // Quote the replacement at today's catalog price, not the
                // old device's cost — the plan is for the new machine.
                'unit_cost' => $asset->replacementCostEstimate() ?? $asset->purchase_cost,
            ]);
        }

        return redirect()->route('orders.show', $order->id)
            ->with('success', trans('admin/purchase-orders/general.forecast_planned_created', ['count' => $assets->count()]));
    }

    public function receiving(Request $request): StreamedResponse
    {
        $this->authorize('procurement.view');

        return $this->streamReportCsv('receiving-status-report', $this->receivingReport($this->resolveFiscalYear($request)));
    }

    public function leasesOperational(Request $request)
    {
        $this->authorize('procurement.view');

        // The leases pages describe the whole portfolio by default — the
        // FY-sticky scope answers a budgeting question these pages are not
        // for. Opening bare lands on ?fiscal_year=all, explicitly.
        if ($redirect = $this->redirectToAllYears($request, 'reports.procurement.leases-operational')) {
            return $redirect;
        }

        return $this->render(
            $request,
            'leases-operational-report',
            trans('admin/purchase-orders/general.report_leases_operational'),
            'reports.procurement.leases-operational',
            $this->leasesOperationalReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function leasesFinancial(Request $request)
    {
        $this->authorize('procurement.view');

        if ($redirect = $this->redirectToAllYears($request, 'reports.procurement.leases-financial')) {
            return $redirect;
        }

        return $this->render(
            $request,
            'leases-financial-report',
            trans('admin/purchase-orders/general.report_leases_financial'),
            'reports.procurement.leases-financial',
            $this->leasesFinancialReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    /**
     * The bare-URL default for the leases pages: every year. Embeds and CSV
     * exports pass their scope explicitly and are left alone.
     */
    private function redirectToAllYears(Request $request, string $routeName)
    {
        if ($request->has('fiscal_year') || $request->boolean('embed') || $request->query('format')) {
            return null;
        }

        return redirect()->route($routeName, ['fiscal_year' => 'all']);
    }

    /**
     * Lease Data Health: every leased device whose record is missing
     * something the end-user dashboard or the buyout flow silently depends
     * on. A lease with no end date counts as "active" everywhere, a Faculty
     * machine with no lessor email has a buyout button that cannot send,
     * and a stale buyout figure keeps printing after the lease ends — this
     * report is where those gaps stop being invisible.
     */
    public function leaseDataHealth(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'lease-data-health-report',
            trans('admin/purchase-orders/general.report_lease_data_health'),
            'reports.procurement.lease-data-health',
            $this->leaseDataHealthReport()
        );
    }

    public function csiSchedule(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'schedule-reconciliation-report',
            trans('admin/purchase-orders/general.report_csi_schedule'),
            'reports.procurement.csi-schedule',
            $this->csiScheduleReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    /**
     * Per-device reconciliation of the live CSI mirror against Snipe — every
     * accepted CSI asset diffed by serial against Snipe's own record
     * (match / schedule mismatch / missing / extra). Driven by the
     * CsiReconciliation engine reading the csi_* mirror tables.
     */
    public function csiReconciliation(Request $request)
    {
        $this->authorize('procurement.view');

        $fy = $this->resolveFiscalYear($request);
        $report = $this->csiReconciliationReport($fy);

        // CSV and dashboard embeds keep the flat table; the page itself has
        // a purpose-built view — discrepancies first, matches folded away.
        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv('lease-reconciliation-report', $report);
        }
        if ($request->boolean('embed')) {
            return $this->embedTable($report);
        }

        return view('reports.procurement.lease-reconciliation', [
            'reportTitle' => trans('admin/purchase-orders/general.report_csi_reconciliation'),
            'grouped' => $report['grouped'],
            'summary' => $report['footer'][0],
            'selectedFy' => $fy,
            'fiscalYears' => $this->availableFiscalYears(),
            'downloadUrl' => route('reports.procurement.csi-reconciliation', array_filter(['format' => 'csv', 'fiscal_year' => $fy])),
        ]);
    }

    private function csiReconciliationReport(?string $fy = null): array
    {
        // Scope by the schedule's commencement fiscal year, read from the
        // contract register (schedule_number -> fiscal_year). Rows whose
        // schedule the register doesn't know stay visible in every view —
        // an unmapped schedule is itself a reconciliation finding.
        $registerFy = Contract::whereNotNull('schedule_number')
            ->pluck('fiscal_year', 'schedule_number')
            ->map(function ($value) {
                return preg_replace_callback('/^FY(\d{2})-(\d{2})$/', fn ($m) => sprintf('FY20%s-%s', $m[1], $m[2]), (string) $value);
            });
        $rowFy = function (array $row) use ($registerFy): ?string {
            foreach ([$row['csi_schedule'] ?? null, $row['snipe_schedule'] ?? null] as $ref) {
                if ($ref && $registerFy->has($ref)) {
                    return $registerFy->get($ref);
                }
            }

            return null;
        };

        $t = fn ($k) => trans('admin/purchase-orders/general.'.$k);

        $columns = [
            $t('csi_recon_status'), $t('csi_recon_serial'), $t('csi_recon_model'),
            $t('csi_recon_csi_schedule'), $t('csi_recon_snipe_schedule'),
            $t('csi_recon_snipe_tag'), $t('csi_recon_snipe_status'),
            $t('csi_recon_snipe_assigned'), $t('csi_recon_snipe_location'),
        ];
        $grouped = ['discrepancies' => [], 'matches' => [], 'unserialized' => []];

        $records = ['discrepancies' => [], 'matches' => [], 'unserialized' => []];
        $tally = ['match' => 0, 'schedule_mismatch' => 0, 'missing_in_snipe' => 0, 'extra_in_snipe' => 0, 'unserialized' => 0];

        foreach ((new CsiReconciliation)->assetDiff() as $row) {
            if ($fy !== null) {
                $scheduleFy = $rowFy($row);
                if ($scheduleFy !== null && $scheduleFy !== $fy) {
                    continue;
                }
            }
            $tally[$row['status']] = ($tally[$row['status']] ?? 0) + 1;
            $bucket = $row['status'] === 'match' ? 'matches'
                : ($row['status'] === 'unserialized' ? 'unserialized' : 'discrepancies');
            $grouped[$bucket][] = $row;
            $records[$bucket][] = [
                'class' => in_array($row['status'], ['match', 'unserialized'], true) ? '' : 'danger',
                'cells' => [
                    $t('csi_recon_'.$row['status']),
                    $row['serial'],
                    $row['model'],
                    $row['csi_schedule'],
                    $row['snipe_schedule'],
                    $row['snipe_tag'],
                    $row['snipe_status'],
                    $row['snipe_assigned'] ?? null,
                    $row['snipe_location'] ?? null,
                ],
            ];
        }

        // `records` stays flat and in bucket order so the CSV export keeps
        // every device on its own line.
        $flat = array_merge($records['discrepancies'], $records['unserialized'], $records['matches']);

        // On screen it reads the other way round. Matches are the bulk and the
        // least interesting thing here — a reconciliation is read for what did
        // NOT line up — so discrepancies and unserialized lines stay flat and
        // on top, and every match folds into one collapsed group beneath them.
        $display = array_merge($records['discrepancies'], $records['unserialized']);

        if (! empty($records['matches'])) {
            $display[] = [
                'class' => '',
                'children_collapsed' => true,
                'cells' => array_merge(
                    [$t('csi_recon_match'), trans('admin/purchase-orders/general.csi_recon_match_fold', ['count' => count($records['matches'])])],
                    array_fill(0, count($columns) - 2, '')
                ),
                'children' => [
                    // The status column is dropped inside the group: every row
                    // in it is a match, which is what the heading already says.
                    'columns' => array_slice($columns, 1),
                    'rows' => array_map(
                        fn ($record) => ['cells' => array_slice($record['cells'], 1)],
                        $records['matches']
                    ),
                ],
            ];
        }

        $summary = $tally['match'].' '.$t('csi_recon_match').' · '
            .$tally['schedule_mismatch'].' '.$t('csi_recon_schedule_mismatch').' · '
            .$tally['missing_in_snipe'].' '.$t('csi_recon_missing_in_snipe').' · '
            .$tally['extra_in_snipe'].' '.$t('csi_recon_extra_in_snipe');

        // The tally carries five buckets and the summary listed four, so an
        // unserialized line was counted and never shown — FY2026-27 rendered
        // 18 rows under a footer accounting for 17. Append it only when there
        // is one: it is a genuine finding (a schedule line with no serial to
        // match on), not a status every reconciliation needs to report.
        if ($tally['unserialized'] > 0) {
            $summary .= ' · '.trans(
                'admin/purchase-orders/general.csi_recon_unserialized_fold',
                ['count' => $tally['unserialized']]
            );
        }

        return [
            'columns' => $columns,
            'records' => $flat,
            'records_display' => $display,
            'grouped' => $grouped,
            'footer' => [$summary, '', '', '', '', '', '', '', ''],
        ];
    }

    /**
     * CSI in-process devices (ordered/shipped, not yet accepted onto a
     * schedule) and whether Snipe already knows each one — the "what's
     * arriving" view for receiving / deployment planning.
     */
    public function csiArrivals(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'incoming-lease-assets-report',
            trans('admin/purchase-orders/general.report_csi_arrivals'),
            'reports.procurement.csi-arrivals',
            $this->csiArrivalsReport()
        );
    }

    private function csiArrivalsReport(): array
    {
        $t = fn ($k) => trans('admin/purchase-orders/general.'.$k);

        // Lessor draft Exhibit "A" emails arrive one Equipment Schedule at a
        // time (e.g. #301452-008), so group the arrivals the same way with a
        // per-schedule subtotal — that is the unit receiving reconciles
        // against. Devices not yet on a schedule bucket under a clear label.
        $pendingLabel = $t('csi_recon_pending_schedule');
        $grouped = [];
        foreach ((new CsiReconciliation)->inProcessArrivals() as $row) {
            $grouped[$row['csi_schedule'] ?: $pendingLabel][] = $row;
        }
        ksort($grouped);

        // Best-effort model-id lookup so the "add to inventory" deep-link can
        // prefill the model, not just the serial. Cached per model name.
        $modelIds = [];
        $modelIdFor = function (?string $name) use (&$modelIds) {
            $name = trim((string) $name);
            if ($name === '') {
                return null;
            }
            if (! array_key_exists($name, $modelIds)) {
                $modelIds[$name] = AssetModel::where('name', $name)->value('id');
            }

            return $modelIds[$name];
        };

        $records = [];
        $totalInSnipe = 0;
        $totalCount = 0;

        foreach ($grouped as $scheduleLabel => $rows) {
            $groupInSnipe = 0;

            foreach ($rows as $row) {
                $groupInSnipe += $row['in_snipe'] ? 1 : 0;

                $record = [
                    'class' => $row['in_snipe'] ? '' : 'warning',
                    'cells' => [
                        $row['csi_schedule'] ?: $pendingLabel,
                        $row['in_snipe'] ? $t('csi_recon_match') : $t('csi_recon_missing'),
                        $row['serial'],
                        $row['model'],
                        $row['snipe_tag'],
                        $row['snipe_status'],
                    ],
                ];

                // Arriving devices Snipe doesn't know yet get a one-click add
                // that deep-links to a create form prefilled with the serial
                // (and the model when it maps to an existing Snipe model).
                if (! $row['in_snipe']) {
                    $params = ['serial' => $row['serial']];
                    if ($modelId = $modelIdFor($row['model'])) {
                        $params['model_id'] = $modelId;
                    }
                    $record['action'] = [
                        'col' => 1,
                        'url' => route('hardware.create', $params),
                        'label' => $t('csi_recon_add_to_inventory'),
                    ];
                }

                $records[] = $record;
            }

            $records[] = [
                'class' => 'info rpt-subtotal',
                'cells' => [
                    $scheduleLabel.' '.trans('admin/orders/general.total'),
                    $groupInSnipe.' / '.count($rows).' '.$t('csi_recon_in_snipe_suffix'),
                    '', '', '', '',
                ],
            ];

            $totalInSnipe += $groupInSnipe;
            $totalCount += count($rows);
        }

        return [
            'columns' => [
                $t('csi_recon_csi_schedule'), $t('csi_recon_status'), $t('csi_recon_serial'),
                $t('csi_recon_model'), $t('csi_recon_snipe_tag'), $t('csi_recon_snipe_status'),
            ],
            'records' => $records,
            'footer' => [
                trans('admin/orders/general.total'),
                $totalInSnipe.' / '.$totalCount.' '.$t('csi_recon_in_snipe_suffix'),
                '', '', '', '',
            ],
        ];
    }

    public function invoiceApproval(Request $request)
    {
        $this->authorize('procurement.view');

        $status = $request->query('status');
        $attestation = $request->query('attestation_type');

        return $this->render(
            $request,
            'invoice-approval-queue',
            trans('admin/purchase-orders/general.report_invoice_approval'),
            'reports.procurement.invoice-approval',
            $this->invoiceApprovalReport($status, $attestation, $this->resolveFiscalYear($request)),
            '',
            array_filter(['status' => $status, 'attestation_type' => $attestation]),
            true
        );
    }

    public function userAgreementLedger(Request $request)
    {
        $this->authorize('procurement.view');

        $typeFilter = $request->query('agreement_type');
        $stageFilter = $request->query('stage');
        $fy = $this->resolveFiscalYear($request);
        $report = $this->userAgreementLedgerReport($typeFilter, $stageFilter, $fy);

        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv('user-agreement-ledger', $report);
        }

        if ($request->boolean('embed')) {
            return $this->embedTable($report);
        }

        return view('reports.procurement.user-agreement-ledger', [
            'reportTitle' => trans('admin/purchase-orders/general.report_user_agreement_ledger'),
            'report' => $report,
            'typeFilter' => $typeFilter,
            'stageFilter' => $stageFilter,
            'selectedFy' => $fy,
            'allFiscalYears' => $this->availableFiscalYears(),
            'downloadUrl' => route('reports.procurement.user-agreement-ledger', array_filter([
                'format' => 'csv',
                'agreement_type' => $typeFilter,
                'stage' => $stageFilter,
                'fiscal_year' => $request->query('fiscal_year', $fy),
            ], fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    /**
     * Lease schedules ending in the selected FY — the budgeting-time
     * decision list, moved off the dashboard face into the Budgeting
     * report group. Serves the full page, the tab embed, and a CSV.
     */
    public function leaseEndSchedulesReport(Request $request)
    {
        $this->authorize('procurement.view');

        $selectedFy = $this->resolveFiscalYear($request);
        $all = $this->leaseEndSchedules();
        $leaseEndSchedules = $selectedFy
            ? array_values(array_filter($all, fn ($s) => $s['fiscal_year'] === $selectedFy))
            : $all;

        if ($request->query('format') === 'csv') {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="lease-end-schedules-'.strtolower($selectedFy ?: 'all').'.csv"',
            ];

            return response()->stream(function () use ($leaseEndSchedules) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Contract', 'Provider', 'Ownership', 'Lease End', 'Fiscal Year', 'Devices', 'Cost']);
                foreach ($leaseEndSchedules as $schedule) {
                    fputcsv($out, [
                        $schedule['contract_id'] ?? '',
                        $schedule['provider'] ?? '',
                        implode(' / ', array_keys($schedule['ownership_counts'] ?? [])),
                        $schedule['lease_end_date'] ?? '',
                        $schedule['fiscal_year'] ?? '',
                        $schedule['count'] ?? 0,
                        $schedule['cost'] ?? 0,
                    ]);
                }
                fclose($out);
            }, 200, $headers);
        }

        $data = [
            'selectedFy' => $selectedFy,
            'leaseEndSchedules' => $leaseEndSchedules,
        ];

        if ($request->boolean('embed')) {
            return view('reports.procurement._lease-end-schedules', $data);
        }

        return view('reports.procurement.lease-end-schedules', $data);
    }

    public function scheduleSigningQueue(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'schedule-signing-queue',
            trans('admin/purchase-orders/general.report_schedule_signing'),
            'reports.procurement.schedule-signing',
            $this->scheduleSigningQueueReport($request->query('stage'), $this->resolveFiscalYear($request)),
            '',
            array_filter(['stage' => $request->query('stage')]),
            true
        );
    }

    /**
     * Mark or unmark an invoice as approved-to-pay. Single PATCH endpoint
     * the Invoice Approval Queue posts to so AP can clear the queue
     * inline instead of hopping to the order page.
     */
    public function updateInvoiceApproval(Request $request, OrderInvoice $invoice): RedirectResponse
    {
        $this->authorize('procurement.view');

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
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'lease-decisions-report',
            trans('admin/purchase-orders/general.report_lease_decisions'),
            'reports.procurement.lease-decisions',
            $this->leaseDecisionsReport($request->query('status'), $this->resolveFiscalYear($request)),
            '',
            array_filter(['status' => $request->query('status')]),
            true
        );
    }

    public function poDisposition(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'po-disposition-report',
            trans('admin/purchase-orders/general.report_po_disposition'),
            'reports.procurement.po-disposition',
            $this->poDispositionReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function extensionWatch(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'extension-watch-report',
            trans('admin/purchase-orders/general.report_extension_watch'),
            'reports.procurement.extension-watch',
            $this->extensionWatchReport(null),
            '',
            [],
            false
        );
    }

    public function aroRegister(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'aro-register-report',
            trans('admin/purchase-orders/general.report_aro_register'),
            'reports.procurement.aro-register',
            $this->aroRegisterReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function assetLeaseDetail(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'asset-lease-detail-report',
            trans('admin/purchase-orders/general.report_asset_lease_detail'),
            'reports.procurement.asset-lease-detail',
            $this->assetLeaseDetailReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function poDrilldown(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'po-drilldown-report',
            trans('admin/purchase-orders/general.report_po_drilldown'),
            'reports.procurement.po-drilldown',
            $this->poDrilldownReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function dispositionGrid(Request $request)
    {
        $this->authorize('procurement.view');

        // ?contract=<lease id> deep-links one contract: it preselects the
        // pane and scopes the downloads to that lease only.
        $contract = trim((string) $request->query('contract'));

        // CSV hand-off flattens the scoped contracts' serials into one table;
        // XLSX mirrors the workbook with one sheet per contract.
        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv(
                'lease-disposition'.($contract !== '' ? '-'.$contract : ''),
                $this->dispositionGridCsv($contract ?: null)
            );
        }
        if ($request->query('format') === 'xlsx') {
            return $this->dispositionGridXlsx($contract ?: null);
        }

        $data = $this->dispositionGridData();
        $canEdit = auth()->user()?->can('create', Order::class) ?? false;
        $canEditAssets = auth()->user()?->can('update', Asset::class) ?? false;

        // Resolve the deep-linked contract to a pane (default: first pane) so
        // the picker, panes and download links all agree on the selection.
        // Exact match first, then substring — so a link minted before a
        // schedule id was renamed (e.g. the 4130- lessor prefix) still lands
        // on the right lease.
        $contractIds = collect($data['contracts'])->pluck('contract_id');
        $selectedContract = $contractIds->first(fn ($id) => strcasecmp($id, $contract) === 0)
            ?? ($contract !== '' ? $contractIds->first(fn ($id) => stripos($id, $contract) !== false) : null)
            ?? ($data['contracts'][0]['contract_id'] ?? '');

        $viewData = [
            'contracts' => $data['contracts'],
            'canEdit' => $canEdit,
            'canEditAssets' => $canEditAssets,
            'selectedContract' => $selectedContract,
            'statusOptions' => $canEditAssets ? Statuslabel::orderBy('name')->pluck('name', 'id') : collect(),
            'downloadUrl' => route('reports.procurement.disposition-grid', array_filter(['format' => 'csv', 'contract' => $selectedContract])),
            'downloadXlsxUrl' => route('reports.procurement.disposition-grid', array_filter(['format' => 'xlsx', 'contract' => $selectedContract])),
        ];

        // Embed mode (dashboard inline) returns just the tabbed grid;
        // standalone returns the full page.
        if ($request->boolean('embed')) {
            return view('reports.procurement._disposition-grid', $viewData);
        }

        return view('reports.procurement.disposition-grid', array_merge($viewData, [
            'reportTitle' => trans('admin/purchase-orders/general.report_disposition_grid'),
            'reportIntro' => trans('admin/purchase-orders/general.report_disposition_grid_desc'),
        ]));
    }

    /**
     * Inline save of a per-device disposition note from the grid. The
     * disposition itself is derived from the device's Snipe status +
     * Decommissioned Date and is not editable; this only stores a free-text
     * note (buyout justification, special case) per asset. An empty note
     * clears the row.
     */
    public function updateDispositionNote(Request $request)
    {
        $this->authorize('create', Order::class);

        $validated = $request->validate([
            'asset_id' => 'required|integer|exists:assets,id',
            'contract_reference' => 'required|string|max:191',
            'notes' => 'nullable|string|max:65535',
        ]);

        $existing = LeaseDecision::where('asset_id', $validated['asset_id'])
            ->orderByDesc('id')
            ->first();

        // Empty note → drop the per-asset note row entirely.
        if (! isset($validated['notes']) || $validated['notes'] === '') {
            $existing?->delete();

            return response()->json(['status' => 'success', 'cleared' => true]);
        }

        $note = $existing ?: new LeaseDecision;
        $note->asset_id = $validated['asset_id'];
        $note->contract_reference = $validated['contract_reference'];
        $note->notes = $validated['notes'];
        $note->created_by = $note->created_by ?: auth()->id();
        $note->save();

        return response()->json(['status' => 'success', 'notes' => (string) $note->notes]);
    }

    /**
     * Inline / bulk save of the lifecycle fields the Disposition Grid reads:
     * the device status (which drives the derived disposition), the
     * Decommissioned Date and the Buyout Cost. Accepts one or many asset ids
     * so a single pencil edit and a multi-select bulk apply share the same
     * path. A field left out of the request is untouched; a field sent empty
     * is cleared (status excepted — a device always has a status).
     */
    public function updateDispositionAssets(Request $request)
    {
        $this->authorize('update', Asset::class);

        $validated = $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer|exists:assets,id',
            'status_id' => 'sometimes|nullable|integer|exists:status_labels,id',
            'decommission_date' => 'sometimes|nullable|date',
            'buyout_cost' => 'sometimes|nullable|numeric|min:0',
        ]);

        $assets = Asset::whereIn('id', $validated['asset_ids'])->get();
        foreach ($assets as $asset) {
            if ($request->filled('status_id')) {
                $asset->status_id = (int) $validated['status_id'];
            }
            if ($request->has('decommission_date')) {
                $asset->decommission_date = $validated['decommission_date'] ?: null;
            }
            if ($request->has('buyout_cost')) {
                $asset->buyout_cost = $validated['buyout_cost'] !== null && $validated['buyout_cost'] !== ''
                    ? $validated['buyout_cost']
                    : null;
            }
            $asset->save();
        }

        return response()->json(['status' => 'success', 'updated' => $assets->count()]);
    }

    /**
     * Inline save of a note on a report row. Generic so any procurement
     * report table can expose an editable (pencil) note cell — the model is
     * whitelisted and the only field touched is `notes`.
     */
    public function updateReportNote(Request $request)
    {
        $this->authorize('create', Order::class);

        $validated = $request->validate([
            'model' => 'required|string|in:lease_decision,lease_plan_note',
            'id' => 'required_if:model,lease_decision|nullable|integer',
            'contract_reference' => 'required_if:model,lease_plan_note|nullable|string|max:191',
            'notes' => 'nullable|string|max:65535',
        ]);

        // lease_plan_note is the contract-level free-text plan on a schedule
        // with no logged decision (or a retained lease-to-own) — a
        // note-only LeaseDecision row (no asset, no decision_type), created
        // on first edit.
        $model = match ($validated['model']) {
            'lease_decision' => LeaseDecision::findOrFail($validated['id']),
            'lease_plan_note' => LeaseDecision::firstOrNew([
                'contract_reference' => $validated['contract_reference'],
                'asset_id' => null,
                'decision_type' => null,
            ]),
            default => abort(422),
        };

        $model->notes = $validated['notes'] ?? '';
        if (! $model->exists) {
            $model->created_by = auth()->id();
        }
        $model->save();

        return response()->json(['status' => 'success', 'notes' => (string) $model->notes]);
    }

    public function creditTerminationLedger(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'credit-termination-ledger',
            trans('admin/purchase-orders/general.report_credit_ledger'),
            'reports.procurement.credit-ledger',
            $this->creditTerminationReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function lessorBreakdown(Request $request)
    {
        $this->authorize('procurement.view');

        $breakdown = $this->lessorBreakdownReport(null);

        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv('lessor-breakdown-report', $breakdown);
        }

        if ($request->boolean('embed')) {
            return $this->embedTable($breakdown);
        }

        // The Leasing page: the lease portfolio in one place — the three
        // charts that used to sit on the reports hub, the lessor breakdown,
        // and the year's rent contract by contract. One page answers "what
        // are we leasing, from whom, and what does this year cost".
        //
        // The FY comes straight off the query rather than the sticky
        // procurement scope: the Annual Rent bars are the year picker here,
        // and clicking through a decade of them should not re-aim every
        // other report's session default.
        return view('reports.procurement.leasing', [
            'breakdown' => $breakdown,
            'rentCosts' => $this->rentCostsReport($request->query('fiscal_year')),
        ]);
    }

    /**
     * One lease, the whole story: the terms, the money, and the device
     * schedule — the in-app mirror of the lessor's own Exhibit A, which is
     * currently a workbook somebody has to go find. Addressable by the
     * contract id itself (/procurement/leasing/301452-007) so any table
     * naming a lease can open it.
     */
    public function leaseDetail(Request $request, string $contract)
    {
        $this->authorize('procurement.view');

        $group = collect($this->groupedLeaseAssets(null))
            ->first(fn ($g) => strcasecmp($g['contract_id'], $contract) === 0);

        abort_unless($group !== null, 404);

        $cols = $this->leaseFieldColumns();
        $basis = $this->leaseRentBasis($group);

        // The register's own term dates, when the contract is on file.
        $term = Contract::where('schedule_number', $group['contract_id'])->first();

        // Per-device money: the asset's own fields first, order items as
        // the transition fallback — the same precedence Leases Contracts
        // applies, so the two pages can never disagree.
        $assetIds = collect($group['assets'])->pluck('id')->all();
        $orderItemsByAsset = OrderItem::with('order.purchaseOrder')
            ->where('item_type', Asset::class)
            ->whereIn('item_id', $assetIds)
            ->get()
            ->groupBy('item_id');

        $devices = [];
        $totals = ['equipment' => 0.0, 'soft' => 0.0, 'rent' => 0.0, 'buyout' => 0.0];
        $poNumbers = [];
        $cdwOrders = [];

        foreach ($group['assets'] as $asset) {
            $items = $orderItemsByAsset->get($asset->id, collect());

            $soft = $cols['warranty_cost'] ? $this->parseMoney($asset->{$cols['warranty_cost']}) : 0.0;
            if ($soft <= 0) {
                $soft = (float) $items->sum('warranty_cost');
            }
            $rent = $cols['lease_rent'] ? $this->parseMoney($asset->{$cols['lease_rent']}) : 0.0;
            $buyout = $cols['buyout_cost'] ? $this->parseMoney($asset->{$cols['buyout_cost']}) : 0.0;
            $equipment = (float) ($asset->purchase_cost ?? 0);

            $totals['equipment'] += $equipment;
            $totals['soft'] += $soft;
            $totals['rent'] += $rent;
            $totals['buyout'] += $buyout;

            $assetPo = $cols['po_number'] ? trim((string) $asset->{$cols['po_number']}) : '';
            if (str_starts_with($assetPo, 'P00')) {
                $poNumbers[$assetPo] = true;
            } else {
                foreach ($items as $item) {
                    if ($poNum = $item->order?->purchaseOrder?->po_number) {
                        $poNumbers[$poNum] = true;
                    }
                }
            }
            if ($cdw = trim((string) $asset->order_number)) {
                $cdwOrders[$cdw] = true;
            } else {
                foreach ($items as $item) {
                    if ($orderNum = $item->order?->order_number) {
                        $cdwOrders[$orderNum] = true;
                    }
                }
            }

            $devices[] = [
                'asset' => $asset,
                'equipment' => $equipment,
                'soft' => $soft,
                'rent' => $rent,
                'buyout' => $buyout,
                'ownership' => $cols['ownership_type'] ? trim((string) $asset->{$cols['ownership_type']}) : '',
            ];
        }

        // Stable read: the schedule in serial order, like the Exhibit.
        usort($devices, fn ($a, $b) => strcmp((string) $a['asset']->serial, (string) $b['asset']->serial));

        return view('reports.procurement.lease-detail', [
            'group' => $group,
            'term' => $term,
            'basis' => $basis,
            'devices' => $devices,
            'totals' => $totals,
            'poNumbers' => array_keys($poNumbers),
            'cdwOrders' => array_keys($cdwOrders),
            'decisions' => LeaseDecision::where('contract_reference', $group['contract_id'])
                ->orderByDesc('decision_date')->get(),
            'closure' => app(LeaseClosure::class)->summarise($group['assets']),
        ]);
    }

    /**
     * The Capital Request — the page that replaces the "Devices Capital
     * Request" workbook sent to the head of finance before each fiscal
     * year. One link, one answer: what is ending, what replaces it at what
     * estimated cost, what is being asked for beyond the refresh, and the
     * total being requested. Forecast figures update as the catalog does,
     * so the March snapshot and the in-year actuals are the same living
     * page instead of two drifting tabs.
     */
    public function capitalRequest(Request $request)
    {
        $this->authorize('procurement.view');

        $data = $this->capitalRequestData($request->query('fiscal_year'));

        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv('capital-request-'.$data['fy'], [
                'columns' => $data['csv']['columns'],
                'records' => $data['csv']['records'],
                'footer' => $data['csv']['footer'],
            ]);
        }

        return view('reports.procurement.capital-request', $data);
    }

    /**
     * The request becoming a basket: one click turns the refresh lines into
     * a draft requisition in the PO Builder — the same grouping, priced the
     * same, with catalog part numbers where the replacement maps to one.
     * The draft is a starting point to refine against quotes, not an order.
     */
    public function capitalRequestDraft(Request $request)
    {
        $this->authorize('create', Requisition::class);

        $data = $this->capitalRequestData($request->input('fiscal_year'));

        // The request already became paper: drafting again would clone the
        // requisition back onto itself.
        if ($data['requisitionBacked']) {
            return redirect()->route('reports.procurement.capital-request', ['fiscal_year' => $data['fy']])
                ->with('error', trans('admin/purchase-orders/general.capital_already_drafted'));
        }

        $lines = $data['refresh']->values();

        if ($lines->isEmpty() && $data['newAskLines']->isEmpty()) {
            return redirect()->route('reports.procurement.capital-request', ['fiscal_year' => $data['fy']])
                ->with('error', trans('general.no_results'));
        }

        $requisition = Requisition::create([
            'title' => trans('admin/purchase-orders/general.capital_request_title').' '.$data['fy'],
            'status' => 'draft',
            'fiscal_year' => $data['fy'],
            // The lineage the REQM/PO columns read back through — only
            // requisitions born from the request are the request's paper.
            'capital_request_fy' => $data['fy'],
            // The supplier the catalog lines belong to — one basket, one
            // vendor. Rows without a mapping ride along as free-form lines.
            'supplier_id' => $lines->pluck('supplier_id')->filter()->first(),
            'gst_rate' => 0.05,
            'pst_rate' => 0,
            'shipping' => 0,
        ]);

        $sort = 0;
        foreach ($lines as $row) {
            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'catalog_item_id' => $row['catalog_item_id'],
                'description' => $row['model'],
                'vendor_sku' => $row['vendor_sku'],
                'mfr_part_number' => $row['mfr_part_number'],
                'quantity' => $row['qty'],
                'unit_of_measure' => 'EA',
                'unit_cost' => round($row['unit'], 2),
                'pst_applicable' => false,
                'sort_order' => $sort++,
            ]);
        }

        // The new asks ride along as free-form lines — CDW's desk prices
        // what has no part number yet, same as any special request.
        foreach ($data['newAskLines'] as $line) {
            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'catalog_item_id' => null,
                'description' => trim($line->need.' — '.$line->description, ' —'),
                'quantity' => $line->quantity,
                'unit_of_measure' => 'EA',
                'unit_cost' => round((float) $line->unit_cost, 2),
                'pst_applicable' => false,
                'sort_order' => $sort++,
            ]);
        }

        return redirect()->route('purchase-orders.builder', ['requisition' => $requisition->id])
            ->with('success', trans('admin/purchase-orders/general.capital_draft_created', ['fy' => $data['fy']]));
    }

    /**
     * @return array<string, mixed>
     */
    private function capitalRequestData(?string $fy): array
    {
        // Same default rule as Rent Costs: no selection means the current
        // fiscal year — the request is always for a specific year.
        $startYear = (int) (now()->month >= 4 ? now()->year : now()->year - 1);
        if ($fy && preg_match('/^FY(\d{4})-\d{2}$/', $this->normalizeFy($fy) ?? '', $m)) {
            $startYear = (int) $m[1];
        }
        $fyLabel = sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);

        $cols = $this->leaseFieldColumns();
        $decisions = $this->leaseDecisionsByContract();

        // ── The budget envelope. The Lease End Schedules register is the
        // authority: every schedule ending in the year has its FULL original
        // value pre-approved for the new fiscal year — the whole envelope is
        // the request, always. The decisions only steer what it buys: a kept
        // lease-to-own contributes its value to the envelope and asks for no
        // devices, which is exactly how its budget gets redistributed.
        $endingSchedules = collect($this->leaseEndSchedules())
            ->where('fiscal_year', $fyLabel)
            ->values();
        $envelope = (float) $endingSchedules->sum('cost');

        // ── The plan, where one exists. A device already placed on a
        // deployment wave carries its planned replacement model — that IS
        // the request line for it, priced from the same catalog mapping the
        // wave board shows. Devices on no wave fall back to the forecast's
        // like-for-like mapping. The wave rides along on the row, so the
        // request reads straight back to the board it came from.
        // Scoped to the assets this request actually iterates — an
        // unscoped read of every deployment item let any stray row that
        // shared a replaces_asset_id silently win the keyBy and flip a
        // device's request year (AB#4489).
        $leaseGroups = $this->groupedLeaseAssets(null);
        $leaseAssetIds = collect($leaseGroups)
            ->flatMap(fn ($g) => collect($g['assets'])->pluck('id'))
            ->filter()->values()->all();
        $waveItems = DeploymentItem::with(['wave:id,name,fiscal_year', 'model.refreshCatalogItem', 'model.category'])
            ->whereNotNull('replaces_asset_id')
            ->whereIn('replaces_asset_id', $leaseAssetIds ?: [0])
            ->get()
            ->keyBy('replaces_asset_id');

        // ── The paper, populated back — but ONLY through explicit lineage:
        // requisitions drafted FROM this capital request (capital_request_fy
        // stamps them at draft time). Matching by product across every FY
        // requisition attached the lease-refresh MacBook Airs to the
        // Foundation labs REQM purely because both bought Airs — a
        // self-contained purchase is not this request's paper.
        $reqByCatalog = [];
        $reqByDescription = [];
        $fyRequisitions = Requisition::with(['purchaseOrder:id,po_number', 'items.catalogItem'])
            ->where('capital_request_fy', $fyLabel)
            ->orderBy('created_at')
            ->get();
        foreach ($fyRequisitions as $req) {
            $ref = [
                'requisition_id' => $req->id,
                'reqm' => $req->requisition_number ? 'REQM '.$req->requisition_number : $req->title,
                'po' => $req->purchaseOrder?->po_number,
            ];
            foreach ($req->items as $reqItem) {
                if ($reqItem->catalog_item_id) {
                    $reqByCatalog[$reqItem->catalog_item_id] ??= $ref;
                }
                if ($reqItem->description) {
                    $reqByDescription[$reqItem->description] ??= $ref;
                }
            }
        }

        // ── The refresh, one device at a time. Which YEAR a device asks in
        // follows the decision chain, strongest first: a wave it is planned
        // into pins it to that wave's fiscal year (an operational call —
        // the FY26-27 faculty wave refreshing 5-year leases at year 4 must
        // land in FY26-27's request, not the year the paper expires); with
        // no wave, the forecast's operative date decides — the EARLIER of
        // its End of Life and its lease end, the same rule the forecast
        // itself applies; lease end alone is only the default when nothing
        // sharper was decided. Kept contracts get no rows anywhere; their
        // story is the envelope's.
        $refresh = [];
        $refreshTotal = 0.0;
        $refreshDevices = 0;

        // ── Once the request has been drafted into a requisition, the
        // paper IS the request. The derived device lines are planning
        // scaffolding for composing the ask; the approvers compare this
        // table against the PO, so from the moment lineage exists the
        // table must read exactly as the requisition — same lines, same
        // quantities, same total, nothing extra.
        $requisitionBacked = $fyRequisitions->isNotEmpty();

        if ($requisitionBacked) {
            foreach ($fyRequisitions as $req) {
                foreach ($req->items as $reqItem) {
                    $catalog = $reqItem->catalogItem;
                    $qty = (int) $reqItem->quantity;
                    $unit = (float) $reqItem->unit_cost;

                    $refresh['paper-'.$req->id.'-'.$reqItem->id] = [
                        'area' => '—',
                        'contract_id' => null,
                        'contract_name' => '',
                        'qty' => $qty,
                        'type' => (string) ($catalog?->category ?: '—'),
                        'model' => (string) $reqItem->description,
                        'unit' => $unit,
                        'estimated' => $catalog === null || $catalog->isEstimate(),
                        'cost' => $qty * $unit,
                        'preference' => '—',
                        'waves' => [],
                        'requisition_id' => $req->id,
                        'reqm' => $req->requisition_number ? 'REQM '.$req->requisition_number : $req->title,
                        'po' => $req->purchaseOrder?->po_number,
                        'retained' => false,
                        'note' => '',
                        'catalog_item_id' => $catalog?->id,
                        'vendor_sku' => $reqItem->vendor_sku,
                        'mfr_part_number' => $reqItem->mfr_part_number,
                        'supplier_id' => $catalog?->supplier_id,
                    ];

                    $refreshTotal += $qty * $unit;
                    $refreshDevices += $qty;
                }
            }
        }

        foreach ($requisitionBacked ? [] : $leaseGroups as $group) {
            $decision = $decisions[$group['contract_id']] ?? null;
            if ($decision && $decision->decision_type === 'buyout'
                && in_array($decision->status, ['approved', 'completed'], true)) {
                continue;
            }

            $contractFy = $this->fiscalYearFromEndDate($group['lease_end_date']);

            foreach ($group['assets'] as $asset) {
                // The device's request year: wave FY, else the earlier of
                // EOL and lease end, else the contract's lease-end year.
                $deviceWaveItem = $waveItems->get($asset->id);
                $waveFy = $this->normalizeFy((string) $deviceWaveItem?->wave?->fiscal_year);

                $eolStr = $asset->asset_eol_date
                    ? Carbon::parse($asset->asset_eol_date)->toDateString()
                    : '';
                $leaseStr = (string) ($asset->lease_end_date ?? '');
                $operativeDate = match (true) {
                    $eolStr !== '' && $leaseStr !== '' => min($eolStr, $leaseStr),
                    $eolStr !== '' => $eolStr,
                    default => $leaseStr,
                };

                $requestFy = $waveFy
                    ?: ($this->fiscalYearFromEndDate($operativeDate) ?? $contractFy);

                if ($requestFy !== $fyLabel) {
                    continue;
                }
                // Disposed units carry budget, not bodies — same rule as
                // the Lease End Schedules headcount.
                $statusName = (string) $asset->status?->name;
                if ($asset->status?->getStatuslabelType() === 'archived'
                    || in_array($statusName, ['Active (Buyouts)', 'Active (Legacy)'], true)) {
                    continue;
                }

                // The wave's planned model wins over the like-for-like
                // forecast: once a device is on a wave, the wave is the plan.
                $waveItem = $deviceWaveItem;
                $plannedModel = $waveItem?->model;

                if ($plannedModel) {
                    $catalog = $plannedModel->refreshCatalogItem;
                    $replacement = $catalog?->name ?: $plannedModel->name;
                    $typeName = (string) ($catalog?->category ?: $plannedModel->category?->name ?: '');
                } else {
                    $catalog = $asset->model?->refreshCatalogItem;
                    $replacement = $catalog?->name ?: ($asset->model?->name ?: trans('general.na'));
                    $typeName = (string) ($catalog?->category ?: $asset->model?->category?->name ?: '');
                }
                $unit = $catalog?->effectiveCost() ?? (float) ($asset->purchase_cost ?? 0);

                $ownership = $cols['ownership_type'] ? trim((string) $asset->{$cols['ownership_type']}) : '';
                $preference = match ($ownership) {
                    'Lease to Own' => trans('admin/purchase-orders/general.capital_pref_lto'),
                    'Lease to Return' => trans('admin/purchase-orders/general.capital_pref_rental'),
                    default => '',
                };
                $area = $cols['usage'] ? trim((string) $asset->{$cols['usage']}) : '';

                $key = implode('|', [$group['contract_id'], $replacement, $area, $preference]);
                if (! isset($refresh[$key])) {
                    $refresh[$key] = [
                        'area' => $area ?: '—',
                        'contract_id' => $group['contract_id'],
                        'contract_name' => $group['contract_name'],
                        'qty' => 0,
                        'type' => $typeName,
                        'model' => $replacement,
                        'unit' => $unit,
                        'estimated' => $catalog === null || $catalog->isEstimate(),
                        'cost' => 0.0,
                        'preference' => $preference ?: '—',
                        'waves' => [],
                        'requisition_id' => null,
                        'reqm' => null,
                        'po' => null,
                        'retained' => false,
                        'note' => '',
                        // Carried so "start a PO draft" can hand the builder
                        // real catalog lines, part numbers and all.
                        'catalog_item_id' => $catalog?->id,
                        'vendor_sku' => $catalog?->vendor_sku,
                        'mfr_part_number' => $catalog?->mfr_part_number,
                        'supplier_id' => $catalog?->supplier_id,
                    ];
                }

                $refresh[$key]['qty']++;
                $refresh[$key]['cost'] += $unit;
                $refreshTotal += $unit;
                $refreshDevices++;

                if ($waveItem?->wave) {
                    $refresh[$key]['waves'][$waveItem->wave->id] = $waveItem->wave->name;
                }
            }
        }

        // Attach the paper trail per line, then read in contract order.
        // Requisition-backed rows already carry their own paper — catalog
        // matching would only re-derive what is literally on the row.
        if (! $requisitionBacked) {
            foreach ($refresh as &$row) {
                $paper = $reqByCatalog[$row['catalog_item_id']] ?? $reqByDescription[$row['model']] ?? null;
                $row['requisition_id'] = $paper['requisition_id'] ?? null;
                $row['reqm'] = $paper['reqm'] ?? null;
                $row['po'] = $paper['po'] ?? null;
            }
            unset($row);
        }

        $refresh = $requisitionBacked
            ? collect($refresh)->values()
            : collect($refresh)->sortBy([['contract_id', 'asc'], ['cost', 'desc']])->values();

        // ── The new asks: entered by hand, exactly as the workbook's "New
        // Ask" rows were. A new ask is a decision, not a derivation — this
        // page understands lease ends and requisitions, nothing about
        // orders already placed.
        $newAskLines = CapitalRequestLine::where('fiscal_year', $fyLabel)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        $newAskTotal = (float) $newAskLines->sum(fn ($line) => $line->lineTotal());

        // The same paper trail for typed lines — the draft writes them as
        // "need — description", so that is the string to find them by.
        $newAskPaper = [];
        foreach ($newAskLines as $line) {
            $newAskPaper[$line->id] = $reqByDescription[trim($line->need.' — '.$line->description, ' —')]
                ?? $reqByDescription[$line->description]
                ?? null;
        }

        // ── The POs this request became, once finance issued them — and the
        // requisitions still in flight ahead of a PO, so the page says where
        // the ask stands, not just what it is.
        $purchaseOrders = PurchaseOrder::where('fiscal_year', $fyLabel)
            ->orderBy('po_number')
            ->get(['id', 'po_number', 'title', 'budget']);

        $openRequisitions = Requisition::with('items')
            ->whereNull('purchase_order_id')
            ->whereIn('status', ['draft', 'submitted', 'requisitioned'])
            ->where('fiscal_year', $fyLabel)
            ->orderBy('created_at')
            ->get();

        // Flat CSV of the one table, in the workbook's column order.
        $csvRecords = [];
        foreach ($refresh as $row) {
            $csvRecords[] = ['cells' => [
                trans('admin/purchase-orders/general.capital_need_refresh'),
                $row['contract_id'], $row['area'], $row['preference'], $row['type'],
                $row['qty'], $row['model'],
                $this->money($row['cost']), $this->money($row['unit']),
                implode(', ', $row['waves']), (string) $row['reqm'], (string) $row['po'],
            ]];
        }
        foreach ($newAskLines as $line) {
            $paper = $newAskPaper[$line->id];
            $csvRecords[] = ['cells' => [
                $line->need, '', (string) $line->area, (string) $line->preference, (string) $line->type,
                $line->quantity, $line->description,
                $this->money($line->lineTotal()), $this->money((float) $line->unit_cost),
                '', (string) ($paper['reqm'] ?? ''), (string) ($paper['po'] ?? ''),
            ]];
        }

        return [
            'fy' => $fyLabel,
            'allFiscalYears' => $this->availableFiscalYears(),
            'envelope' => $envelope,
            'endingSchedules' => $endingSchedules,
            'requisitionBacked' => $requisitionBacked,
            'capitalRequisitions' => $fyRequisitions,
            'refresh' => $refresh,
            'refreshTotal' => $refreshTotal,
            'refreshDevices' => $refreshDevices,
            'newAskLines' => $newAskLines,
            'newAskPaper' => $newAskPaper,
            'newAskTotal' => $newAskTotal,
            // The request is the envelope, always — the allocation below it
            // says how much of it the lines account for so far.
            'remaining' => $envelope - $refreshTotal - $newAskTotal,
            'purchaseOrders' => $purchaseOrders,
            'openRequisitions' => $openRequisitions,
            'csv' => [
                'columns' => [
                    trans('admin/purchase-orders/general.capital_col_need'),
                    trans('admin/purchase-orders/general.capital_col_ending_contract'),
                    trans('admin/purchase-orders/general.capital_col_area'),
                    trans('admin/purchase-orders/general.capital_col_schedule'),
                    trans('admin/purchase-orders/general.capital_col_type'),
                    trans('admin/purchase-orders/general.lease_qty'),
                    trans('admin/purchase-orders/general.forecast_model'),
                    trans('admin/purchase-orders/general.capital_col_cost'),
                    trans('admin/purchase-orders/general.capital_col_unit'),
                    trans('admin/purchase-orders/general.capital_col_wave'),
                    trans('admin/purchase-orders/general.capital_col_reqm'),
                    trans('admin/purchase-orders/general.capital_col_po'),
                ],
                'records' => $csvRecords,
                'footer' => [
                    trans('admin/orders/general.total'), '', '', '', '',
                    $refreshDevices + (int) $newAskLines->sum('quantity'), '',
                    $this->money($refreshTotal + $newAskTotal), '', '', '', '',
                ],
            ],
        ];
    }

    /** Add one manually entered New Ask line to the year's capital request. */
    public function capitalRequestLineStore(Request $request)
    {
        $this->authorize('create', Requisition::class);

        $validated = $request->validate([
            'fiscal_year' => 'required|string|max:16',
            'area' => 'nullable|string|max:191',
            'need' => 'required|string|max:191',
            'type' => 'nullable|string|max:191',
            'description' => 'required|string|max:191',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'required|numeric|min:0',
            'preference' => 'nullable|string|max:191',
        ]);

        $validated['fiscal_year'] = $this->normalizeFy($validated['fiscal_year']) ?? $validated['fiscal_year'];
        CapitalRequestLine::create($validated);

        return redirect()->route('reports.procurement.capital-request', ['fiscal_year' => $validated['fiscal_year']]);
    }

    public function capitalRequestLineDestroy(CapitalRequestLine $line)
    {
        $this->authorize('create', Requisition::class);

        $fy = $line->fiscal_year;
        $line->delete();

        return redirect()->route('reports.procurement.capital-request', ['fiscal_year' => $fy]);
    }

    /**
     * The capital request's money in four numbers, for pages that plan
     * against it — the forecast shows this strip so adjusting the plan and
     * watching the funds happen on the same screen. Public for the same
     * reason lessorBreakdownData once was: another controller assembles a
     * page around it.
     *
     * @return array{fy: string, envelope: float, requested: float, remaining: float}
     */
    public function capitalSummary(?string $fy): array
    {
        $data = $this->capitalRequestData($fy);

        return [
            'fy' => $data['fy'],
            'envelope' => (float) $data['envelope'],
            'requested' => (float) $data['refreshTotal'] + (float) $data['newAskTotal'],
            'remaining' => (float) $data['remaining'],
        ];
    }

    public function rentCosts(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'rent-costs-report',
            trans('admin/purchase-orders/general.report_rent_costs'),
            'reports.procurement.rent-costs',
            $this->rentCostsReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function pstApplicability(Request $request)
    {
        $this->authorize('procurement.view');

        return $this->render(
            $request,
            'pst-applicability-report',
            trans('admin/purchase-orders/general.report_pst_applicability'),
            'reports.procurement.pst-applicability',
            $this->pstApplicabilityReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function tax(Request $request): StreamedResponse
    {
        $this->authorize('procurement.view');

        return $this->streamReportCsv('tax-summary-report', $this->taxReport($this->resolveFiscalYear($request)));
    }

    /**
     * Per-purchase-order budget vs. spend.
     */
    private function poBudgetReport(?string $fy = null): array
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
            ->when($fy, fn ($query) => $query->where('fiscal_year', $fy))
            ->orderBy('po_number')
            ->get();

        $records = [];
        $totalBudget = $totalInvoiced = $totalCommitted = $totalRemaining = $totalOrders = 0.0;

        // Committed is sourced from the asset records (equipment + warranty),
        // FY-scoped by acquisition date — see assetCommittedByPo().
        $assetCommitted = $this->assetCommittedByPo($fy);

        foreach ($purchaseOrders as $po) {
            // Spend is FY-scoped by acquisition date; budget stays the PO's
            // annual figure. For a blanket PO viewed outside its home FY the
            // remaining column reads against that annual budget — the
            // per-FY budget split lands with the carry-over work.
            $invoiced = $po->invoicedTotalForFy($fy);
            $committed = $assetCommitted[$po->po_number] ?? 0.0;
            $remaining = $po->budget === null ? null : (float) $po->budget - $committed;
            $overBudget = $po->budget !== null && $committed > (float) $po->budget;
            $orderCount = $fy ? $po->orders->where('fiscal_year', $fy)->count() : $po->orders->count();

            $totalBudget += (float) $po->budget;
            $totalInvoiced += $invoiced;
            $totalCommitted += $committed;
            $totalRemaining += ($remaining ?? 0);
            $totalOrders += $orderCount;

            $records[] = [
                'class' => $overBudget ? 'danger' : '',
                // The purchase order is the document an order is placed from, so
                // its number is the way into the work rather than a label to
                // read off. Opens in the lightbox, like every other report link.
                'links' => [0 => route('purchase-orders.show', $po)],
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
                    $overBudget ? trans('general.yes') : trans('general.no'),
                    $orderCount,
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
    private function invoicesReport(?string $fy = null): array
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

        $invoices = $this->scopeInvoiceToFiscalYear(
            OrderInvoice::with('order.purchaseOrder', 'items'),
            $fy
        )
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
                'links' => array_filter([
                    0 => $invoice->order?->purchaseOrder
                        ? route('purchase-orders.show', $invoice->order->purchaseOrder) : null,
                    1 => $invoice->order ? route('orders.show', $invoice->order->id) : null,
                ]),
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
    private function receivingReport(?string $fy = null): array
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
            ->when($fy, fn ($query) => $query->where('fiscal_year', $fy))
            ->with('purchaseOrder', 'supplier', 'items')
            ->orderBy('order_number')
            ->get();

        $records = [];
        foreach ($orders as $order) {
            $total = $order->items->count();
            $received = $order->items->whereNotNull('received_at')->count();
            $records[] = [
                'class' => '',
                'links' => array_filter([
                    0 => $order->purchaseOrder ? route('purchase-orders.show', $order->purchaseOrder) : null,
                    1 => route('orders.show', $order->id),
                ]),
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
    private function taxReport(?string $fy = null): array
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

        $purchaseOrders = PurchaseOrder::with('orders.invoices')
            ->when($fy, fn ($query) => $query->where('fiscal_year', $fy))
            ->orderBy('po_number')
            ->get();

        $records = [];
        foreach ($purchaseOrders as $po) {
            $orders = $fy ? $po->orders->where('fiscal_year', $fy) : $po->orders;
            $invoices = $orders->flatMap->invoices;
            $records[] = [
                'class' => '',
                'links' => [0 => route('purchase-orders.show', $po)],
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

        $orphanInvoices = $this->scopeInvoiceToFiscalYear(
            OrderInvoice::whereHas('order', fn ($query) => $query->whereNull('purchase_order_id')),
            $fy
        )->get();

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
    private function capitalReport(bool $forecast = false, ?string $fy = null): array
    {
        $columns = [
            trans('admin/purchase-orders/general.fiscal_year'),
            trans('admin/purchase-orders/general.cost_center'),
            trans('admin/purchase-orders/general.purchase_orders'),
            trans('admin/purchase-orders/general.budget'),
            trans('admin/purchase-orders/general.committed'),
            trans('admin/purchase-orders/general.remaining'),
        ];

        $purchaseOrders = PurchaseOrder::with('orders.invoices', 'orders.items')
            ->when($fy, fn ($query) => $query->where('fiscal_year', $fy))
            ->get();

        $groups = $purchaseOrders->groupBy(function ($po) {
            return ($po->fiscal_year ?: '—').'||'.($po->cost_center ?: '—');
        });

        $records = [];
        $totalBudget = $totalCommitted = $totalPlanned = 0.0;

        // Committed is sourced from the asset records — see assetCommittedByPo().
        $assetCommitted = $this->assetCommittedByPo($fy);

        foreach ($groups as $key => $group) {
            [$fiscalYear, $costCenter] = explode('||', $key);
            $budget = $group->sum(fn ($po) => (float) $po->budget);
            $committed = $group->sum(fn ($po) => $assetCommitted[$po->po_number] ?? 0.0);
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

        // When scoped to a single fiscal year, surface the approved budget
        // basis the dashboard shows — otherwise a year funded entirely by
        // carry-forward (no POs cut yet) renders as a blank report. Carry-
        // forward is independent of this year's PO budgets, so it never
        // double-counts; the allocation basis is only added when there are no
        // PO groups, mirroring the dashboard's "allocations OR po-budgets" rule.
        if ($fy) {
            $poRowsExist = $records !== [];

            if (! BudgetAllocation::where('fiscal_year', $fy)->where('source', 'carry_forward')->exists()) {
                $carry = BudgetCarry::intoFy($fy);
                if ($carry && $carry['unused'] > 0) {
                    $totalBudget += $carry['unused'];
                    // The carry-forward is last year's unused PO budget, so name
                    // the source POs that funded it rather than a bare "—".
                    $carryPos = PurchaseOrder::where('fiscal_year', $carry['source_fy'])
                        ->where('budget', '>', 0)
                        ->orderBy('po_number')
                        ->pluck('po_number')
                        ->all();
                    $records[] = [
                        'class' => 'info',
                        'cells' => [
                            $fy,
                            trans('admin/purchase-orders/general.capital_carry_forward', ['source' => $carry['source_fy']]),
                            $carryPos ? implode(', ', $carryPos) : '—',
                            $this->money($carry['unused']),
                            $this->money(0),
                            $this->money($carry['unused']),
                        ],
                    ];
                }
            }

            if (! $poRowsExist) {
                $allocBudget = (float) BudgetAllocation::where('fiscal_year', $fy)
                    ->where('source', '!=', 'carry_forward')
                    ->sum('amount');
                if ($allocBudget > 0) {
                    $totalBudget += $allocBudget;
                    $records[] = [
                        'class' => 'info',
                        'cells' => [
                            $fy,
                            trans('admin/purchase-orders/general.capital_allocations'),
                            '—',
                            $this->money($allocBudget),
                            $this->money(0),
                            $this->money($allocBudget),
                        ],
                    ];
                }
            }
        }

        if ($forecast) {
            $plannedGroups = Order::planned()
                ->when($fy, fn ($query) => $query->where('fiscal_year', $fy))
                ->with('items')->get()
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
    private function capitalModeToggle(bool $forecast, ?string $fy = null): string
    {
        $fyParam = ($fy === null || $fy === '') ? [] : ['fiscal_year' => $fy];

        return '<div class="btn-group" role="group">'
            .'<a href="'.route('reports.procurement.capital', $fyParam).'" class="btn btn-sm '.($forecast ? 'btn-default' : 'btn-primary').'">'
            .e(trans('admin/purchase-orders/general.mode_actual')).'</a>'
            .'<a href="'.route('reports.procurement.capital', array_merge(['mode' => 'forecast'], $fyParam)).'" class="btn btn-sm '.($forecast ? 'btn-primary' : 'btn-default').'">'
            .e(trans('admin/purchase-orders/general.mode_forecast')).'</a>'
            .'</div> ';
    }

    /**
     * Assets reaching end-of-life within the next year — the refresh
     * pipeline. purchase_cost stands in as the replacement-cost estimate.
     */
    /**
     * Logical lease field => native `assets` column. These lived in Snipe-IT
     * custom fields (`_snipeit_*`); the F2 migration moved them to native typed
     * columns and dropped the custom fields, so the reports read native
     * directly. Native names are stable across environments, so the old
     * per-name db_column lookup is gone. The key set (and its order,
     * matching the sharepoint.csv export) is unchanged, so every caller that
     * reads `$asset->{$columns[...]}` keeps working. See
     * docs/lease-native-roadmap.md.
     */
    private function leaseFieldColumns(): array
    {
        return [
            'contract_id' => 'lease_contract_id',
            'contract_name' => 'lease_contract_name',
            'lease_end_date' => 'lease_end_date',
            'ownership_type' => 'ownership_type',
            'lease_rent' => 'lease_rent',
            'buyout_cost' => 'buyout_cost',
            'usage' => 'lease_usage',
            'area' => 'lease_area',
            'decommission_date' => 'decommission_date',
            'book_value' => 'lease_book_value',
            'po_number' => 'po_number',
            'warranty_cost' => 'warranty_soft_cost',
        ];
    }

    /**
     * Finance-facing label for a device's Usage tag. The `Usage` custom
     * field is populated by the inventory automations from the assignment:
     * a device assigned to a location is "Shared" (a shared lab / classroom
     * machine = Curriculum) and a device assigned to a person is "Assigned"
     * (an individual staff machine = Admin). Finance — and the BC PST
     * school-supplies exemption — read these as Curriculum / Admin, the
     * same split the Leases workbook carries. Unknown or blank values pass
     * through unchanged so nothing is silently relabelled.
     */
    private function useLabel(?string $usage): string
    {
        return match ($this->useClass($usage)) {
            'curriculum' => trans('admin/purchase-orders/general.use_curriculum'),
            'admin' => trans('admin/purchase-orders/general.use_admin'),
            default => trim((string) $usage),
        };
    }

    /**
     * Classify a Usage tag as 'curriculum', 'admin', or null (unknown).
     * Accepts both the raw automation values (Shared / Assigned) and a
     * value that already reads Curriculum / Admin, so it works whatever the
     * upstream field happens to hold.
     */
    private function useClass(?string $usage): ?string
    {
        $usage = trim((string) $usage);
        if ($usage === '') {
            return null;
        }
        if (strcasecmp($usage, 'Shared') === 0 || stripos($usage, 'curriculum') !== false) {
            return 'curriculum';
        }
        if (strcasecmp($usage, 'Assigned') === 0 || stripos($usage, 'admin') !== false) {
            return 'admin';
        }

        return null;
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

        // CCA Financial schedules: bare ECI* historically, 4130-ECI* since
        // the lessor-account prefix landed (2026-08). CSI Leasing: 301452-*.
        return str_starts_with($contractId, 'ECI')
            || str_starts_with($contractId, '4130-ECI')
            || str_starts_with($contractId, '301452-');
    }

    /**
     * CSI Leasing handles the 301452-* schedules; CCA Financial owns the
     * ECI* contracts. Mirrors the provider mapping in the TDX sync.
     */
    private function contractProvider(string $contractId): string
    {
        return str_starts_with($contractId, '301452-') ? 'CSI Leasing' : 'CCA Financial';
    }

    /**
     * Extract a CSI schedule reference (`301452-008`) from the asset's
     * "PO Number" field. The 007/008 acquisitions were filed with the
     * schedule in that field and an empty Lease Contract ID, so this is the
     * fallback that keeps them in the lease rollups. Values like
     * `301452-008-041426` collapse to `301452-008`; anything else (a
     * university PO such as `P0025420`, or blank) yields null.
     */
    private function scheduleFromPoField(?string $value): ?string
    {
        if ($value && preg_match('/^(301452-\d{3})/', trim($value), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Committed spend per university purchase order, computed from the ASSET
     * source of truth: each device's purchase_cost (equipment) plus its
     * Warranty/Soft Cost field, grouped by the university PO carried on the
     * asset's "PO Number" field, scoped to a fiscal year by purchase_date.
     *
     * This is what makes committed reconcile to the real, received fleet
     * instead of the drifted order-item import. Outstanding (not-yet-shipped)
     * orders have no asset, so they fall to the Orders model rather than
     * inflating committed. Returns [po_number => committed_total].
     *
     * Shared with the budget carry-forward via App\Services\AssetCommitted
     * so both read the same number.
     */
    private function assetCommittedByPo(?string $fy = null): array
    {
        return AssetCommitted::byPo($fy);
    }

    /**
     * Convert a Lease End Date string to the ECU fiscal-year label in the
     * canonical four-digit-start `FY2025-26` shape, so lease-end data shares
     * an axis with order-driven committed/planned data (see normalizeFy).
     *
     * Uses ECU's April-March fiscal boundary — the same one
     * Helper::currentFiscalYear applies to orders — so a lease ending in,
     * say, May 2026 lands in FY2026-27. An April-March end date belongs to
     * FY{Y-1}-{Y}; April onward to FY{Y}-{Y+1}.
     */
    private function fiscalYearFromEndDate($endDateStr): ?string
    {
        if (empty($endDateStr)) {
            return null;
        }

        // Native lease_end_date is a Carbon date since the F2 migration;
        // its string form carries a time (Y-m-d H:i:s) that fails every
        // format below, which silently zeroed the lease-end pre-approval.
        if ($endDateStr instanceof \DateTimeInterface) {
            $month = (int) $endDateStr->format('m');
            $year = (int) $endDateStr->format('Y');
            $start = $month >= 4 ? $year : $year - 1;

            return sprintf('FY%d-%02d', $start, ($start + 1) % 100);
        }

        $endDate = null;
        foreach (['Y-m-d', 'm/d/Y', 'Y/m/d', 'd/m/Y'] as $format) {
            $endDate = \DateTime::createFromFormat($format, trim((string) $endDateStr));
            if ($endDate !== false) {
                break;
            }
        }

        if (! $endDate) {
            return null;
        }

        $month = (int) $endDate->format('m');
        $year = (int) $endDate->format('Y');

        $start = $month >= 4 ? $year : $year - 1;
        $end = $start + 1;

        return sprintf('FY%d-%02d', $start, $end % 100);
    }

    /**
     * Devices whose lease end falls within each FY, with $-rollup.
     * Drives the "Lease-end pre-approval" card and the third dataset
     * on the FY chart: a lease ending in FYNN is the implicit
     * replacement budget for FYNN (CSI/CCA Financial already pre-approved
     * the equivalent spend when the original schedule was signed).
     *
     * Derived from leaseEndSchedules(): EVERY ending schedule's value is
     * pre-approved for the new FY — the lease's original total was approved
     * at signing and rolls forward whatever the renewal decision is. A
     * logged buyout / return / extension changes what we actually buy
     * (types/quantities are re-assessed at renewal), not whether the budget
     * is approved, so no schedule is subtracted from the estimate.
     */
    private function leaseExpiryByFy(array $schedules): array
    {
        $byFy = [];
        foreach ($schedules as $schedule) {
            $fy = $schedule['fiscal_year'];
            $byFy[$fy] ??= ['count' => 0, 'cost' => 0.0];
            $byFy[$fy]['count'] += $schedule['count'];
            $byFy[$fy]['cost'] += $schedule['cost'];
        }

        ksort($byFy);

        return $byFy;
    }

    /**
     * Every lease schedule with an end date, rolled up per contract:
     * device count, model mix, replacement-cost estimate (purchase_cost,
     * same convention as the EOL forecast) and the logged lease decision,
     * ordered by end date. Buyout / legacy / archived assets are excluded
     * from the device count — they're no longer active lease commitments —
     * but their cost stays in the estimate: the pre-approval envelope is the
     * schedule's full original lease value, and the dollar value is what
     * drives the new fiscal year's budget, not the headcount.
     *
     * `refresh_planned` no longer gates the pre-approval estimate — every
     * schedule's value is pre-approved (see leaseExpiryByFy) — it now only
     * drives the row badge: true when no decision is logged yet (default =
     * replace at term) or the decision is an explicit 'replace'; false for
     * buyout (lease-to-own), return and extend, where the value is still
     * pre-approved but the device needs are re-assessed at renewal.
     */
    private function leaseEndSchedules(): array
    {
        $columns = $this->leaseFieldColumns();
        $endDateColumn = $columns['lease_end_date'];
        $contractIdColumn = $columns['contract_id'];
        $ownershipColumn = $columns['ownership_type'];

        if (! $endDateColumn || ! $contractIdColumn) {
            return [];
        }

        $assets = Asset::with('model', 'status', 'lessor')
            // lease_end_date is a native DATE since the F2 migration —
            // an empty-string guard compares a date to '' and matches
            // nothing, which silently emptied every lease-end view.
            ->whereNotNull($endDateColumn)
            ->whereNotNull($contractIdColumn)
            ->where($contractIdColumn, '!=', '')
            ->get();

        $decisions = $this->leaseDecisionsByContract();
        $planNotes = $this->leasePlanNotesByContract();

        $schedules = [];
        foreach ($assets as $asset) {
            $contractId = $asset->{$contractIdColumn};
            if (! $this->isValidContractId($contractId)) {
                continue;
            }

            $fy = $this->fiscalYearFromEndDate($asset->{$endDateColumn});
            if (! $fy) {
                continue;
            }

            if (! isset($schedules[$contractId])) {
                $decision = $decisions[$contractId] ?? null;
                $planNote = $planNotes[$contractId] ?? null;
                $schedules[$contractId] = [
                    'contract_id' => $contractId,
                    'provider' => $asset->lessor?->name ?: $this->contractProvider($contractId),
                    'lease_end_date' => $asset->{$endDateColumn},
                    'fiscal_year' => $fy,
                    'count' => 0,
                    'cost' => 0.0,
                    'model_counts' => [],
                    'ownership_counts' => [],
                    'decision' => $decision,
                    'plan_note' => $planNote ? (string) $planNote->notes : '',
                    'refresh_planned' => $decision === null || $decision->decision_type === 'replace',
                    'is_lease_to_own' => false,
                ];
            }

            // A contract counts as lease-to-own as soon as any of its assets
            // carries that ownership type. Lease-to-own equipment is simply
            // retained at term end — it needs no buyout/return decision — so
            // the view renders it as "retained", never as a logged decision.
            if ($ownershipColumn && (string) $asset->{$ownershipColumn} === 'Lease to Own') {
                $schedules[$contractId]['is_lease_to_own'] = true;
            }

            // Tally the ownership-type mix across every device on the schedule
            // (disposed units included, same basis as the cost envelope) so the
            // table can show what kind of contract each ending lease is.
            if ($ownershipColumn) {
                $ownership = trim((string) $asset->{$ownershipColumn});
                if ($ownership !== '') {
                    $schedules[$contractId]['ownership_counts'][$ownership] =
                        ($schedules[$contractId]['ownership_counts'][$ownership] ?? 0) + 1;
                }
            }

            // The pre-approval envelope is the schedule's full original lease
            // value, so every device's cost rolls forward into the new FY
            // regardless of how the unit was ultimately disposed — the dollar
            // value is the driver, not the headcount.
            $schedules[$contractId]['cost'] += (float) $asset->purchase_cost;

            // The device count, by contrast, reflects only the units still
            // actively coming off lease: a device already bought out, returned
            // or moved to a legacy/archived status is no longer part of the
            // refresh headcount (its budget stays, its body doesn't).
            $statusName = (string) $asset->status?->name;
            $statusType = $asset->status?->getStatuslabelType();
            $disposed = $statusType === 'archived'
                || in_array($statusName, ['Active (Buyouts)', 'Active (Legacy)'], true);
            if ($disposed) {
                continue;
            }

            $schedules[$contractId]['count']++;

            $modelName = $asset->model?->name ?: trans('general.na');
            $modelName = html_entity_decode($modelName, ENT_QUOTES | ENT_HTML5);
            $schedules[$contractId]['model_counts'][$modelName] =
                ($schedules[$contractId]['model_counts'][$modelName] ?? 0) + 1;
        }

        foreach ($schedules as &$schedule) {
            arsort($schedule['model_counts']);
        }
        unset($schedule);

        usort($schedules, fn ($a, $b) => [$a['lease_end_date'], $a['contract_id']] <=> [$b['lease_end_date'], $b['contract_id']]);

        return array_values($schedules);
    }

    /**
     * The latest non-cancelled decision logged against each lease
     * contract, keyed by contract reference. Ordered by decision date so
     * keyBy keeps the most recent call when a contract has several.
     */
    private function leaseDecisionsByContract(): array
    {
        // Note-only rows (decision_type null) carry a plan note for a
        // contract without a logged decision — they are not decisions and
        // must not flip a schedule's badge, so they're excluded here and
        // read separately by leasePlanNotesByContract().
        return LeaseDecision::whereNull('asset_id')
            ->whereNotNull('decision_type')
            ->where('status', '!=', 'cancelled')
            ->orderBy('decision_date')
            ->get()
            ->keyBy('contract_reference')
            ->all();
    }

    /**
     * Contract-level plan notes: LeaseDecision rows with no asset and no
     * decision type. They hold the free-text plan a schedule row shows (and
     * edits inline) before any buyout / return / extension is logged, and
     * the per-row note on retained (lease-to-own) schedules.
     */
    private function leasePlanNotesByContract(): array
    {
        return LeaseDecision::whereNull('asset_id')
            ->whereNull('decision_type')
            ->orderBy('id')
            ->get()
            ->keyBy('contract_reference')
            ->all();
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
    private function groupedLeaseAssets(?string $fy = null): array
    {
        $columns = $this->leaseFieldColumns();
        $contractIdColumn = $columns['contract_id'];
        $poNumberColumn = $columns['po_number'];

        if (! $contractIdColumn) {
            return [];
        }

        // Acquisition-FY scope: a lease schedule belongs to the fiscal year
        // its assets were bought in (003-006 → FY2025-26, 007/008 → FY2026-27,
        // and so on, two schedules per quarter). purchase_date stands in for
        // the schedule's open quarter until the lessor finalises it.
        //
        // Pull assets that carry a Lease Contract ID *or* a CSI schedule
        // parked in the PO Number field (the 007/008 acquisitions), so the
        // latter aren't silently dropped from the lease rollups.
        $assets = $this->scopeDateToFiscalYear(
            // assignedTo is deliberately NOT eager loaded: the morphTo is
            // named 'assigned', so with('assignedTo') fills the wrong
            // relation key and $asset->assignedTo reads null on every row.
            Asset::with('model', 'status', 'lessor', 'defaultLoc')
                ->where(function ($q) use ($contractIdColumn, $poNumberColumn) {
                    $q->where(fn ($w) => $w->whereNotNull($contractIdColumn)->where($contractIdColumn, '!=', ''));
                    if ($poNumberColumn) {
                        $q->orWhere($poNumberColumn, 'like', '301452-%');
                    }
                }),
            $fy,
            'purchase_date'
        )->get();

        $groups = [];
        foreach ($assets as $asset) {
            $contractId = $asset->{$contractIdColumn};

            // Fall back to a CSI schedule sitting in the PO Number field when
            // the Lease Contract ID is blank/invalid (007/008 data drift).
            if (! $this->isValidContractId($contractId) && $poNumberColumn) {
                $contractId = $this->scheduleFromPoField($asset->{$poNumberColumn});
            }
            if (! $this->isValidContractId($contractId)) {
                continue;
            }

            if (! isset($groups[$contractId])) {
                $groups[$contractId] = [
                    'contract_id' => $contractId,
                    'contract_name' => null,
                    'lease_end_date' => null,
                    'provider' => $asset->lessor?->name ?: $this->contractProvider($contractId),
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
            $statusType = $asset->status?->getStatuslabelType();

            if ($statusType === 'archived') {
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
    /**
     * One row per leased asset with a data gap, worst problems first.
     * Checks mirror what the record is actually used for:
     *
     *   - no lease end date        → treated as an active lease forever, and
     *                                the /my row shows no date at all
     *   - unknown / missing        → invisible to every per-contract report
     *     contract reference         and to the reconciliation
     *   - no lessor email          → the buyout request has nowhere to send
     *   - Faculty with no buyout   → /my shows no estimate to the person
     *     cost                       deciding whether to buy out
     *   - buyout cost after the    → a dead figure that keeps printing on
     *     lease ended                /my and in exports
     *   - no catalog tag while     → neither the buyout nor the refresh
     *     deployed                   doorway renders for the assigned person
     *
     * Archived devices only appear for the stale-buyout check — their other
     * gaps are history, not work.
     */
    private function leaseDataHealthReport(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.detail_asset_tag'),
            trans('admin/purchase-orders/general.detail_serial'),
            trans('admin/purchase-orders/general.detail_model'),
            trans('general.assignee'),
            trans('general.catalog'),
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.lease_end_date'),
            trans('admin/purchase-orders/general.health_problems'),
        ];

        $knownContracts = Contract::whereNotNull('schedule_number')
            ->pluck('schedule_number')
            ->map(fn ($number) => strtoupper(trim((string) $number)))
            ->flip();

        $assets = Asset::with('model', 'status', 'lessor')
            ->where('ownership_type', 'like', '%lease%')
            ->get();

        $records = [];
        $problemCount = 0;

        foreach ($assets as $asset) {
            $end = $asset->leaseEndDate();
            $active = $end === null || $end->gte(today());
            $archived = (int) ($asset->status->archived ?? 0) === 1;
            $contractRef = strtoupper(trim((string) $asset->lease_contract_id));

            $problems = [];

            if (! $archived) {
                if (! $asset->lease_end_date) {
                    $problems[] = ['danger', trans('admin/purchase-orders/general.health_no_end_date')];
                }
                if ($contractRef === '') {
                    $problems[] = ['danger', trans('admin/purchase-orders/general.health_no_contract')];
                } elseif (! isset($knownContracts[$contractRef])) {
                    $problems[] = ['warning', trans('admin/purchase-orders/general.health_unknown_contract')];
                }
                if ($active && (! $asset->lessor || ! filled($asset->lessor->email))) {
                    $problems[] = ['danger', trans('admin/purchase-orders/general.health_no_lessor_email')];
                }
                if ($active && $asset->isFacultyCatalog() && ! is_numeric($asset->buyout_cost)) {
                    $problems[] = ['warning', trans('admin/purchase-orders/general.health_no_buyout_cost')];
                }
                if ($active && $asset->assigned_type === User::class
                    && trim((string) $asset->catalogTag()) === '') {
                    $problems[] = ['warning', trans('admin/purchase-orders/general.health_no_catalog_tag')];
                }
            }

            if (! $active && is_numeric($asset->buyout_cost) && ! $asset->decommission_date) {
                $problems[] = ['warning', trans('admin/purchase-orders/general.health_stale_buyout')];
            }

            if (empty($problems)) {
                continue;
            }

            $problemCount += count($problems);
            $worst = collect($problems)->pluck(0)->contains('danger') ? 'danger' : 'warning';

            $records[] = [
                'class' => $worst,
                'cells' => [
                    $asset->asset_tag,
                    (string) $asset->serial,
                    (string) $asset->model?->name,
                    $asset->assignedTo?->present()->fullName ?? '',
                    (string) $asset->catalogTag(),
                    (string) $asset->lease_contract_id,
                    $this->dateString($asset->lease_end_date),
                    collect($problems)->pluck(1)->implode('; '),
                ],
            ];
        }

        // Danger rows first so the report leads with what is broken, not
        // merely untidy; ties keep contract order for scannability.
        usort($records, fn ($a, $b) => [$a['class'] === 'danger' ? 0 : 1, $a['cells'][5], $a['cells'][0]]
            <=> [$b['class'] === 'danger' ? 0 : 1, $b['cells'][5], $b['cells'][0]]);

        $footer = [
            trans('admin/purchase-orders/general.health_footer', [
                'assets' => count($records),
                'problems' => $problemCount,
                'leased' => $assets->count(),
            ]), '', '', '', '', '', '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    private function leasesOperationalReport(?string $fy = null): array
    {
        $columns = [
            trans('admin/purchase-orders/general.lease_provider'),
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.lease_contract_name'),
            trans('admin/purchase-orders/general.lease_end_date'),
            trans('admin/purchase-orders/general.lease_fy_ending'),
            trans('admin/purchase-orders/general.lease_ownership'),
            trans('admin/purchase-orders/general.lease_assets'),
            trans('admin/purchase-orders/general.lease_active'),
            trans('admin/purchase-orders/general.lease_buyouts'),
            trans('admin/purchase-orders/general.lease_archived'),
            trans('admin/purchase-orders/general.lease_models'),
        ];

        $records = [];
        $totalAssets = $totalActive = $totalBuyout = $totalArchived = 0;
        $assetsPerContract = [];
        $ownershipTotals = [];

        foreach ($this->groupedLeaseAssets($fy) as $group) {
            $totalAssets += count($group['assets']);
            $totalActive += $group['active'];
            $totalBuyout += $group['buyout'];
            $totalArchived += $group['archived'];

            $assetsPerContract[$group['contract_id']] = count($group['assets']);
            foreach ($group['ownership_counts'] as $type => $count) {
                $ownershipTotals[$type] = ($ownershipTotals[$type] ?? 0) + $count;
            }

            $records[] = [
                // Buyout-only contracts are dimmed: they're history, not a
                // commitment we still need to manage.
                'class' => $group['active'] === 0 ? 'text-muted' : '',
                'cells' => [
                    $group['provider'],
                    $group['contract_id'],
                    (string) $group['contract_name'],
                    $this->dateString($group['lease_end_date']),
                    (string) $this->fiscalYearFromEndDate($group['lease_end_date']),
                    $this->summariseCounts($group['ownership_counts']),
                    count($group['assets']),
                    $group['active'],
                    $group['buyout'],
                    $group['archived'],
                    $this->summariseCounts($group['model_counts']),
                ],
                'links' => [1 => route('reports.procurement.lease-detail', $group['contract_id'])],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '', '',
            $totalAssets, $totalActive, $totalBuyout, $totalArchived, '',
        ];

        arsort($assetsPerContract);
        ksort($ownershipTotals);

        return [
            'columns' => $columns,
            'records' => $records,
            'footer' => $footer,
            // Every column but the trailing Models list stays on one line.
            'nowrap_except_last' => true,
            'charts' => [
                [
                    'id' => 'leases-assets-per-contract',
                    'title' => trans('admin/purchase-orders/general.leases_chart_assets_per_contract'),
                    'type' => 'bar',
                    'labels' => array_keys($assetsPerContract),
                    'data' => array_values($assetsPerContract),
                    'money' => false,
                ],
                [
                    'id' => 'leases-ownership-mix',
                    'title' => trans('admin/purchase-orders/general.leases_chart_ownership_mix'),
                    'type' => 'doughnut',
                    'labels' => array_keys($ownershipTotals),
                    'data' => array_values($ownershipTotals),
                    'money' => false,
                ],
                [
                    'id' => 'leases-lifecycle',
                    'title' => trans('admin/purchase-orders/general.leases_chart_lifecycle'),
                    'type' => 'doughnut',
                    'labels' => [
                        trans('admin/purchase-orders/general.lease_active'),
                        trans('admin/purchase-orders/general.lease_buyouts'),
                        trans('admin/purchase-orders/general.lease_archived'),
                    ],
                    'data' => [$totalActive, $totalBuyout, $totalArchived],
                    'money' => false,
                ],
            ],
        ];
    }

    /**
     * Lease financial view. For every contract: equipment cost (sum of
     * asset purchase_cost), warranty/soft cost (sum of order-item
     * warranty_cost for the same assets), total, and the distinct PO and
     * CDW order numbers that funded it.
     */
    private function leasesFinancialReport(?string $fy = null): array
    {
        $columns = [
            trans('admin/purchase-orders/general.lease_provider'),
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.lease_end_date'),
            trans('admin/purchase-orders/general.lease_fy_ending'),
            trans('admin/purchase-orders/general.lease_assets'),
            trans('admin/purchase-orders/general.lease_equipment_cost'),
            trans('admin/purchase-orders/general.lease_warranty_cost'),
            trans('admin/purchase-orders/general.lease_total_cost'),
            trans('admin/purchase-orders/general.lease_pos'),
            trans('admin/purchase-orders/general.lease_cdw_orders'),
        ];

        $groups = $this->groupedLeaseAssets($fy);
        // Look up the lease custom-field DB columns without clobbering the
        // human-readable header row built above (they are the generated
        // `_snipeit_*` column names, not display labels).
        $leaseCols = $this->leaseFieldColumns();
        $poNumberColumn = $leaseCols['po_number'];
        $warrantyColumn = $leaseCols['warranty_cost'] ?? null;

        // Order items are the transition fallback for assets whose own PO /
        // CDW / warranty fields aren't populated yet. Keyed by asset id so the
        // per-contract loop stays O(assets).
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
        $costPerContract = [];
        $costByLessor = [];

        foreach ($groups as $group) {
            $equipmentCost = $group['total_cost'];
            $warrantyCost = 0.0;
            $poNumbers = [];
            $cdwOrders = [];

            foreach ($group['assets'] as $asset) {
                $items = $orderItemsByAsset->get($asset->id, collect());

                // Warranty: prefer the asset's own Warranty/Soft Cost field;
                // fall back to the order item until the field is populated.
                $assetWarranty = $warrantyColumn ? $this->parseMoney($asset->{$warrantyColumn}) : 0.0;
                $warrantyCost += $assetWarranty > 0 ? $assetWarranty : (float) $items->sum('warranty_cost');

                // PO: prefer the university PO on the asset's own "PO Number"
                // field; fall back to the order item's purchase order.
                $assetPo = $poNumberColumn ? trim((string) $asset->{$poNumberColumn}) : '';
                if (str_starts_with($assetPo, 'P00')) {
                    $poNumbers[$assetPo] = true;
                } else {
                    foreach ($items as $item) {
                        if ($poNum = $item->order?->purchaseOrder?->po_number) {
                            $poNumbers[$poNum] = true;
                        }
                    }
                }

                // CDW order: prefer the asset's native order_number; fall back
                // to the linked order's number.
                if ($cdw = trim((string) $asset->order_number)) {
                    $cdwOrders[$cdw] = true;
                } else {
                    foreach ($items as $item) {
                        if ($orderNum = $item->order?->order_number) {
                            $cdwOrders[$orderNum] = true;
                        }
                    }
                }
            }

            $contractTotal = $equipmentCost + $warrantyCost;
            $totalAssets += count($group['assets']);
            $totalEquipment += $equipmentCost;
            $totalWarranty += $warrantyCost;
            $totalCost += $contractTotal;

            $costPerContract[$group['contract_id']] = round($contractTotal, 2);
            $costByLessor[$group['provider']] = round(($costByLessor[$group['provider']] ?? 0) + $contractTotal, 2);

            $records[] = [
                'class' => '',
                'cells' => [
                    $group['provider'],
                    $group['contract_id'],
                    $this->dateString($group['lease_end_date']),
                    (string) $this->fiscalYearFromEndDate($group['lease_end_date']),
                    count($group['assets']),
                    $this->money($equipmentCost),
                    $this->money($warrantyCost),
                    $this->money($contractTotal),
                    implode(', ', array_keys($poNumbers)),
                    implode(', ', array_keys($cdwOrders)),
                ],
                'links' => [1 => route('reports.procurement.lease-detail', $group['contract_id'])],
                'cdw_order_numbers' => array_keys($cdwOrders),
            ];
        }

        // Vendor order numbers open the order record in the lightbox. The
        // multilinks map is render-time only (like links), so the plain
        // imploded cell still feeds the CSV/XLSX exports; numbers with no
        // matching Order record stay plain text.
        $orderIdsByNumber = Order::whereIn(
            'order_number',
            collect($records)->flatMap(fn ($r) => $r['cdw_order_numbers'])->unique()->values()->all()
        )->pluck('id', 'order_number');

        foreach ($records as &$record) {
            $linked = collect($record['cdw_order_numbers'])
                ->filter(fn ($number) => $orderIdsByNumber->has($number))
                ->map(fn ($number) => [
                    'label' => $number,
                    'url' => route('orders.show', $orderIdsByNumber[$number]),
                ])
                ->values()
                ->all();
            if ($linked) {
                $record['multilinks'] = [9 => $linked];
            }
            unset($record['cdw_order_numbers']);
        }
        unset($record);

        $footer = [
            trans('admin/orders/general.total'), '', '', '',
            $totalAssets,
            $this->money($totalEquipment),
            $this->money($totalWarranty),
            $this->money($totalCost),
            '', '',
        ];

        arsort($costPerContract);
        arsort($costByLessor);

        return [
            'columns' => $columns,
            'records' => $records,
            'footer' => $footer,
            'nowrap_except_last' => true,
            'charts' => [
                [
                    'id' => 'leases-cost-per-contract',
                    'title' => trans('admin/purchase-orders/general.leases_chart_cost_per_contract'),
                    'type' => 'bar',
                    'labels' => array_keys($costPerContract),
                    'data' => array_values($costPerContract),
                    'money' => true,
                ],
                [
                    'id' => 'leases-cost-by-lessor',
                    'title' => trans('admin/purchase-orders/general.leases_chart_cost_by_lessor'),
                    'type' => 'doughnut',
                    'labels' => array_keys($costByLessor),
                    'data' => array_values($costByLessor),
                    'money' => true,
                ],
                [
                    'id' => 'leases-cost-split',
                    'title' => trans('admin/purchase-orders/general.leases_chart_cost_split'),
                    'type' => 'doughnut',
                    'labels' => [
                        trans('admin/purchase-orders/general.lease_equipment_cost'),
                        trans('admin/purchase-orders/general.lease_warranty_cost'),
                    ],
                    'data' => [round($totalEquipment, 2), round($totalWarranty, 2)],
                    'money' => true,
                ],
            ],
        ];
    }

    /**
     * CSI Schedule Reconciliation. For every 301452-* contract, lists each
     * model as its own line: qty, unit equipment cost, unit warranty cost,
     * line total, plus the distinct POs and CDW orders the model was
     * billed against. Mirrors the per-schedule reconciliation tables in
     * docs/FY2026-27/CSI_Schedule_Reconciliation.md.
     */
    private function csiScheduleReport(?string $fy = null): array
    {
        $columns = [
            trans('general.lessor'),
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

        $warrantyColumn = $this->leaseFieldColumns()['warranty_cost'] ?? null;

        // Restrict to CSI schedules — ECI* contracts have their own
        // CCA Financial reconciliation and don't fit the schedule layout.
        $groups = array_filter(
            $this->groupedLeaseAssets($fy),
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
            // The schedule's lessor, read from its assets (they all carry
            // the same lessor; first non-empty wins).
            $lessorName = '';
            foreach ($group['assets'] as $asset) {
                if ($asset->lessor?->name) {
                    $lessorName = (string) $asset->lessor->name;
                    break;
                }
            }

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

                // Warranty comes off the asset first and only falls back to the
                // order item — the same precedence leasesFinancialReport() uses.
                // Reading order_items alone reported $0.00 warranty on every CSI
                // line: the schedule assets all carry warranty_soft_cost while
                // their order_items.warranty_cost is 0, so schedule 003 showed
                // its equipment total ($264,254.83) as the whole line and the
                // two reports disagreed by $30,051.28 over the same assets.
                $assetWarranty = $warrantyColumn ? $this->parseMoney($asset->{$warrantyColumn}) : 0.0;
                $itemWarranty = (float) $orderItemsByAsset->get($asset->id, collect())->sum('warranty_cost');
                $byModel[$modelName]['warranty_total'] += $assetWarranty > 0 ? $assetWarranty : $itemWarranty;

                foreach ($orderItemsByAsset->get($asset->id, collect()) as $item) {
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
                        $lessorName,
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
                'class' => 'info rpt-subtotal',
                'cells' => [
                    '',
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
            trans('admin/orders/general.total'), '', '', $totalQty, '', '',
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

    private function invoiceApprovalReport(?string $statusFilter = null, ?string $attestationFilter = null, ?string $fy = null): array
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

        $this->scopeInvoiceToFiscalYear($query, $fy);

        if ($statusFilter !== 'all') {
            $query->where('approval_status', $statusFilter);
        }

        // Filter on attestation_type so the lessor-OKP queue (the assets team's
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
     * User Agreement Program Top-Up Ledger. Every user agreement
     * — pickup, paid upgrade, or lease-end buyout — appears on one
     * timeline with its lifecycle stage, financial impact and signed-
     * agreement status. Replaces the multi-sheet SharePoint workbook
     * the assets team maintains by hand.
     */
    private function userAgreementLedgerReport(?string $typeFilter = null, ?string $stageFilter = null, ?string $fy = null): array
    {
        $query = UserAgreement::with('user', 'asset')
            ->orderByRaw(...$this->fieldOrder('lifecycle_stage', [
                'eligible', 'quoted', 'agreement_sent', 'agreement_signed',
                'deployed', 'in_repayment', 'paid_off', 'closed_buyout', 'closed', 'cancelled',
            ]))
            ->orderBy('updated_at', 'desc');

        // Not created_at: every agreement was written by the same backfill, so
        // that column dates the import rather than the programme cycle and the
        // year filter selected nothing. See UserAgreement::scopeForProgramFiscalYear.
        $query->forProgramFiscalYear($fy);

        if ($typeFilter && in_array($typeFilter, UserAgreement::AGREEMENT_TYPES, true)) {
            $query->where('agreement_type', $typeFilter);
        }
        if ($stageFilter && in_array($stageFilter, UserAgreement::LIFECYCLE_STAGES, true)) {
            $query->where('lifecycle_stage', $stageFilter);
        }

        $agreements = $query->get();

        // The unit is the USER: one row per person, one column per
        // agreement type they can hold, so a member's whole program
        // position reads on a single line instead of scattering across
        // interleaved rows.
        $types = $agreements->pluck('agreement_type')->unique()->values()->all();

        $columns = array_merge(
            [
                trans('admin/purchase-orders/general.user_agreement_member'),
                trans('admin/purchase-orders/general.detail_asset_tag'),
                trans('admin/purchase-orders/general.detail_serial'),
                trans('admin/user-agreements/general.originating_contract'),
                trans('admin/purchase-orders/general.user_agreement_stage'),
            ],
            array_map(fn ($type) => trans('admin/purchase-orders/general.user_agreement_type_value_'.$type), $types),
            [trans('admin/orders/general.total')]
        );

        $records = [];
        $totalValue = 0.0;

        $byUser = $agreements->groupBy(fn ($agreement) => $agreement->user_id ?: ('a-'.$agreement->id));
        foreach ($byUser as $group) {
            $first = $group->first();
            $asset = $group->pluck('asset')->filter()->first();
            $userTotal = 0.0;

            $typeCells = [];
            foreach ($types as $type) {
                $ofType = $group->where('agreement_type', $type);
                if ($ofType->isEmpty()) {
                    $typeCells[] = '—';

                    continue;
                }
                $value = (float) $ofType->sum(fn ($agreement) => $agreement->contractValue());
                $userTotal += $value;
                $typeCells[] = $this->money($value);
            }

            $totalValue += $userTotal;

            // One status pill per row — the same lifecycle pill the Faculty
            // Program tracker uses — linking to the agreement record. Rows
            // spanning agreements in different stages get one pill each.
            //
            // A cancelled agreement alongside a live one does not describe
            // the member, so it is left off the row: declining the buyout on
            // your old laptop cancels that agreement, and a red Cancelled
            // pill next to someone whose application is progressing normally
            // reads as "this person's application was cancelled" — which is
            // what happened to the first wave-2 applicant. Only a member
            // whose every agreement is cancelled reads as cancelled; the
            // cancelled row itself is still one click away on its record.
            $live = $group->where('lifecycle_stage', '!=', 'cancelled');
            $stages = ($live->isNotEmpty() ? $live : $group)
                ->pluck('lifecycle_stage')->unique()->values();
            $stagePills = $stages->map(fn ($stage) => [
                'label' => trans('admin/purchase-orders/general.user_agreement_stage_value_'.$stage),
                'class' => UserAgreement::STAGE_LABEL_CLASS[$stage] ?? 'default',
            ])->all();

            $records[] = [
                'class' => '',
                'links' => array_filter([
                    0 => $first->user ? route('users.show', $first->user->id) : null,
                    1 => $asset ? route('hardware.show', $asset->id) : null,
                    2 => $asset ? route('hardware.show', $asset->id) : null,
                    4 => route('user-agreements.show', $first),
                ], fn ($link) => $link !== null),
                'pills' => [4 => $stagePills],
                'cells' => array_merge(
                    [
                        (string) ($first->user?->full_name ?? '—'),
                        (string) ($asset?->asset_tag ?? ''),
                        (string) ($asset?->serial ?? ''),
                        (string) ($group->pluck('lease_contract')->filter()->unique()->implode(', ')),
                        $stages->map(fn ($stage) => trans('admin/purchase-orders/general.user_agreement_stage_value_'.$stage))->implode(' / '),
                    ],
                    $typeCells,
                    [$this->money($userTotal)]
                ),
            ];
        }

        $footer = array_merge(
            [trans('admin/orders/general.total'), '', '', '', ''],
            array_fill(0, count($types), ''),
            [$this->money($totalValue)]
        );

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Lease Decision Tracker — surfaces the buyout/return/extend/replace
     * decisions logged against expiring leases (the PR #17 table) inside
     * the procurement reports area so finance doesn't have to find the
     * Settings link.
     */
    private function leaseDecisionsReport(?string $statusFilter = null, ?string $fy = null): array
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
            ->whereNull('asset_id')
            ->whereNotNull('decision_type')
            ->orderByRaw(...$this->fieldOrder('status', ['pending', 'approved', 'completed', 'cancelled']))
            ->orderBy('decision_date');

        $this->scopeDateToFiscalYear($query, $fy, 'decision_date');

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
                'editable_note' => ['col' => 5, 'model' => 'lease_decision', 'id' => $decision->id],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '',
            $this->money($totalAmount), '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Year-End PO Disposition. For every purchase order, the over/under
     * vs. budget and a suggested year-end disposition: close the PO,
     * roll the remaining commitment to the next fiscal year, or
     * reallocate the surplus to operating. Replaces the year-end
     * walk-through Rod writes Mark by hand in Excel.
     */
    private function poDispositionReport(?string $fy = null): array
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
            ->when($fy, fn ($query) => $query->where('fiscal_year', $fy))
            ->orderBy('fiscal_year')
            ->orderBy('po_number')
            ->get();

        $records = [];
        $totalBudget = $totalInvoiced = $totalCommitted = 0.0;

        // Committed is sourced from the asset records — see assetCommittedByPo().
        $assetCommitted = $this->assetCommittedByPo($fy);

        foreach ($purchaseOrders as $po) {
            $budget = (float) $po->budget;
            $invoiced = $po->invoicedTotalForFy($fy);
            $committed = $assetCommitted[$po->po_number] ?? 0.0;
            $remaining = $budget - $committed;
            $orders = $fy ? $po->orders->where('fiscal_year', $fy) : $po->orders;
            $openOrders = $orders->filter(fn ($o) => ! in_array($o->status, ['received', 'cancelled'], true))->count();

            $totalBudget += $budget;
            $totalInvoiced += $invoiced;
            $totalCommitted += $committed;

            $disposition = $this->dispositionFor($po, $remaining, $openOrders);

            $records[] = [
                'class' => $remaining < 0 ? 'danger' : ($openOrders > 0 ? 'warning' : ''),
                'links' => [0 => route('purchase-orders.show', $po)],
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
     * Extension Watch. Leases that have run *past their original term* and
     * are still in holdover — the "expensive to keep extending" ones. A
     * lease only qualifies once today is past its original-term end date
     * (48 months for a rental, 60 for lease-to-own, measured from the
     * earliest asset purchase). Leases still inside their original term are
     * not extensions, no matter how far in the future their end date sits —
     * this is a live holdover watchlist, never scoped to a fiscal year.
     */
    private function extensionWatchReport(?string $fy = null): array
    {
        // Holdover watchlist — always evaluated against the full portfolio.
        $fy = null;
        $now = new \DateTime('today');

        // The watch is a focused slice of the Disposition Grid: what we are
        // behind on, and what it costs monthly to stay behind. The lease end
        // date leads (the thing being overrun), the estimated monthly cost
        // sits right beside it, and each still-open device row carries its
        // checkout state so the person to chase is on the report itself.
        $columns = [
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.lease_end_date'),
            trans('admin/purchase-orders/general.extension_monthly_cost'),
            trans('admin/purchase-orders/general.extension_months'),
            trans('admin/purchase-orders/general.lease_assets'),
            trans('admin/purchase-orders/general.extension_still_open'),
        ];

        $records = [];
        $closure = app(LeaseClosure::class);

        // Contractual term dates, keyed by schedule. The register knows what a
        // lease actually runs for; guessing 48/60 months off the first purchase
        // flagged ECI20221101 (a real 2022-11-01 -> 2027-12-01 term) as extended
        // when it has years left.
        $terms = Contract::whereNotNull('schedule_number')
            ->where('schedule_number', '!=', '')
            ->get(['schedule_number', 'start_date', 'end_date'])
            ->keyBy('schedule_number');

        foreach ($this->groupedLeaseAssets($fy) as $group) {
            $state = $closure->summarise($group['assets']);

            // A lease whose every device has gone back or been bought out is
            // finished — there is nothing left to extend, chase or pay for.
            // Without this a schedule returned in full two years ago accrued
            // "months extended" forever; ECI20210601A, 23 of 23 returned with
            // decommission dates, was the worst-looking row on the report.
            if ($state['is_closed']) {
                continue;
            }

            $term = $terms->get($group['contract_id']);

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

            // Term length, for amortising the cost. The register's own dates
            // when it has both, else the 48/60-month convention.
            $isLeaseToOwn = ! empty($group['ownership_counts']['Lease to Own']);
            $termMonths = $isLeaseToOwn ? 60 : 48;
            if ($term?->start_date && $term->end_date) {
                $days = (int) (new \DateTime($term->start_date->format('Y-m-d')))
                    ->diff(new \DateTime($term->end_date->format('Y-m-d')))->format('%r%a');
                $termMonths = max(1, (int) round($days / 30.44));
            }

            // The watch is driven by the lease end date carried on the devices,
            // not by a term computed from the first purchase. That guess had
            // flagged ECI20221101 — a genuine 2022-11-01 to 2027-12-01 term —
            // as extended, while the register's own end date can disagree with
            // the devices outright: ECI20220201 reads 2027-10-01 on the
            // contract and 2026-04-01 on all 26 assets. The device date is what
            // the fleet is actually being held against, so it governs here and
            // the disagreement is surfaced rather than silently resolved.
            $monthsPastEnd = (($now->format('Y') - $leaseEnd->format('Y')) * 12)
                + ((int) $now->format('m') - (int) $leaseEnd->format('m'));

            // The watch covers the decision window either side of the end date:
            // ending soon enough to need renew/return/buy called now, or lapsed
            // recently enough to still be a live negotiation. A lease years past
            // its end with a few devices never checked in is not a lease
            // decision any more, it is a records problem — those belong on Lease
            // Data Health, not here, and mixing them made this report unreadable.
            if ($monthsPastEnd < -self::EXTENSION_LOOKAHEAD_MONTHS
                || $monthsPastEnd > self::EXTENSION_LOOKBACK_MONTHS) {
                continue;
            }

            $months = max(0, $monthsPastEnd);
            $originalEnd = $term?->end_date
                ? new \DateTime($term->end_date->format('Y-m-d'))
                : (clone $earliestPurchase)->modify("+{$termMonths} months");
            $datesDisagree = $term?->end_date
                && $term->end_date->format('Y-m-d') !== $leaseEnd->format('Y-m-d');

            // Lease Rent is only usable when every unit carries one: summing a
            // handful of populated values reported the whole contract's rent
            // from a fraction of it — ECI20200301 read $24,929.70/month for ten
            // devices off six values. Otherwise amortise the contract over its
            // term, and say which basis was used rather than implying precision.
            $rentIsComplete = $group['monthly_rent_total'] > 0
                && $this->assetsWithRent($group['assets']) === count($group['assets']);
            $monthlyCost = $rentIsComplete
                ? $group['monthly_rent_total']
                : $group['total_cost'] / $termMonths;

            // One child row per still-open device, so a contract can be
            // traced to the units to actually chase — serial (opens the
            // record in the lightbox), tag, who or where it is checked out
            // to, status and ownership type. Closed units are omitted: they
            // are the part of the lease already dealt with. Nested under the
            // contract with their own headings, because device rows borrowed
            // the contract's columns before — serial numbers were rendering
            // under "Lease End Date".
            $childRows = [];
            foreach ($state['open_assets'] as $asset) {
                $target = $asset->assigned_to ? $asset->assignedTo : null;
                $childRows[] = [
                    'cells' => [
                        $asset->serial,
                        $asset->asset_tag,
                        $this->describeAssignedTo($target) ?: (string) $asset->defaultLoc?->name,
                        $asset->status?->name,
                        trim((string) $asset->ownership_type),
                    ],
                    'links' => [0 => route('hardware.show', $asset->id)],
                ];
            }

            // The device dates govern the watch (see above); the register's
            // own term end still matters when it disagrees, so the conflict
            // rides along on the contract cell instead of a dedicated
            // Original End column.
            $records[] = [
                'class' => $months > 12 ? 'danger' : ($months > 0 ? 'warning' : ''),
                'cells' => [
                    $group['contract_id']
                        .($datesDisagree ? ' '.trans('admin/purchase-orders/general.extension_date_conflict_contract', ['date' => $originalEnd->format('Y-m-d')]) : ''),
                    $leaseEnd->format('Y-m-d'),
                    $this->money($monthlyCost)
                        .($rentIsComplete ? '' : ' '.trans('admin/purchase-orders/general.extension_cost_estimated')),
                    $months,
                    count($group['assets']),
                    $state['open'],
                ],
                'strong' => [1 => true],
                'links' => [0 => route('reports.procurement.lease-detail', $group['contract_id'])],
                'children' => [
                    'columns' => [
                        trans('admin/hardware/table.serial'),
                        trans('admin/hardware/table.asset_tag'),
                        trans('admin/hardware/table.checkoutto'),
                        trans('admin/hardware/table.status'),
                        trans('admin/purchase-orders/general.detail_ownership'),
                    ],
                    'rows' => $childRows,
                ],
            ];
        }

        return ['columns' => $columns, 'records' => $records];
    }

    /**
     * How many of these assets carry a Lease Rent value — the test for whether
     * a summed rent figure describes the whole contract or only part of it.
     *
     * @param  iterable<Asset>  $assets
     */
    private function assetsWithRent(iterable $assets): int
    {
        $column = $this->leaseFieldColumns()['lease_rent'];
        if (! $column) {
            return 0;
        }

        $count = 0;
        foreach ($assets as $asset) {
            if ($this->parseMoney($asset->{$column}) > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Asset Retirement Obligation register. Mark needs to book obligations
     * for known end-of-useful-life costs: buyouts, return fees and disposal.
     * This view aggregates the LeaseDecision log into one finance-ready
     * table, one row per contract+decision-type.
     */
    private function aroRegisterReport(?string $fy = null): array
    {
        $columns = [
            trans('admin/lease-decisions/general.contract_reference'),
            trans('admin/purchase-orders/general.aro_source'),
            trans('admin/lease-decisions/general.decision_type'),
            trans('admin/lease-decisions/general.amount'),
            trans('admin/lease-decisions/general.status'),
            trans('admin/lease-decisions/general.decision_date'),
            trans('general.notes'),
        ];

        $records = [];
        $total = 0.0;

        $canEdit = auth()->user()?->can('create', Order::class) ?? false;
        $canDelete = auth()->user()?->can('delete', Order::class) ?? false;

        // Lease-to-own contracts carry no retirement obligation — the
        // equipment is simply kept at term end, no return is owed and no
        // buyout is paid — so they never contribute cost rows. They still
        // appear, as explicit zero-cost "Retained" lines further down, so
        // the register says the keep happened instead of silently omitting
        // the contract. Keyed to the contract's lease end date, because the
        // retained line is only true near term end.
        $leaseToOwnContracts = [];

        // Real per-asset Buyout Cost values aggregated per contract —
        // the contractual obligation regardless of whether the buyout has
        // been booked as a LeaseDecision yet. Only shown when the field
        // contains real numbers.
        foreach ($this->groupedLeaseAssets($fy) as $group) {
            if (! empty($group['ownership_counts']['Lease to Own'])) {
                $leaseToOwnContracts[$group['contract_id']] = $group['lease_end_date'];

                continue;
            }
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
                    '',
                ],
            ];
        }

        // Logged decisions — buyout or return amounts a human has signed
        // off on (or proposed). Cancelled is excluded.
        $decisions = $this->scopeDateToFiscalYear(
            LeaseDecision::query()
                ->whereNull('asset_id')
                ->whereIn('decision_type', ['buyout', 'return'])
                ->whereNotIn('status', ['cancelled']),
            $fy,
            'decision_date'
        )
            ->orderBy('contract_reference')
            ->get();

        $retainedDecisions = [];
        foreach ($decisions as $decision) {
            // A decision logged against a lease-to-own contract is the
            // retention call, not a costed obligation — hold it for the
            // Retained block below rather than booking it as a buyout.
            // array_key_exists, not isset: the map's values are lease end
            // dates and a contract without one is still lease-to-own.
            if (array_key_exists($decision->contract_reference, $leaseToOwnContracts)) {
                $retainedDecisions[$decision->contract_reference] = $decision;

                continue;
            }
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
                    (string) $decision->notes,
                ],
                'editable_note' => ['col' => 6, 'model' => 'lease_decision', 'id' => $decision->id],
                'row_actions' => $this->leaseDecisionRowActions($decision, $canEdit, $canDelete),
            ];
        }

        // Retained lease-to-own contracts: the equipment is kept at term end,
        // so there is no return obligation and no buyout cost — the row's job
        // is to say that decision was made, at zero cost impact.
        foreach ($leaseToOwnContracts as $contractId => $leaseEndRaw) {
            $decision = $retainedDecisions[$contractId] ?? null;

            // "Kept at term end" is only a fact once term end is in sight.
            // Without this, a lease-to-own signed this year showed up as a
            // Retained decision five years before anyone could make it —
            // 301452-008, ending 2031, read as already settled. Synthetic
            // rows wait for the decision window; a row a human logged is
            // theirs to keep (and now theirs to edit or delete).
            if (! $decision && ! $this->withinLeaseDecisionWindow($leaseEndRaw)) {
                continue;
            }

            $record = [
                'class' => '',
                'cells' => [
                    $contractId,
                    $decision
                        ? trans('admin/purchase-orders/general.aro_source_decision')
                        : trans('admin/purchase-orders/general.aro_source_ownership'),
                    trans('admin/purchase-orders/general.aro_action_retained'),
                    '',
                    $decision
                        ? trans('admin/lease-decisions/general.status_'.$decision->status)
                        : trans('admin/purchase-orders/general.aro_status_contractual'),
                    $decision ? $this->dateString($decision->decision_date) : '',
                    $decision ? (string) $decision->notes : trans('admin/purchase-orders/general.aro_retained_note'),
                ],
            ];
            if ($decision) {
                $record['editable_note'] = ['col' => 6, 'model' => 'lease_decision', 'id' => $decision->id];
                $record['row_actions'] = $this->leaseDecisionRowActions($decision, $canEdit, $canDelete);
            }
            $records[] = $record;
        }

        $footer = [
            trans('admin/orders/general.total'), '', '',
            $this->money($total), '', '', '',
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
    }

    /**
     * Whether a contract's term end is close enough that an end-of-term
     * fact (retained, returned, bought out) can honestly be stated. Uses
     * the same lookahead as the Extension Watch — the two reports are
     * describing the same window from opposite sides. A date that will not
     * parse is treated as out of window: that contract's problem is data,
     * and it belongs on Lease Data Health rather than in the register.
     */
    private function withinLeaseDecisionWindow(?string $raw): bool
    {
        if (empty($raw)) {
            return false;
        }

        $leaseEnd = null;
        foreach (['Y-m-d', 'm/d/Y'] as $fmt) {
            $leaseEnd = \DateTime::createFromFormat($fmt, $raw);
            if ($leaseEnd !== false) {
                break;
            }
        }

        if (! $leaseEnd) {
            return false;
        }

        return $leaseEnd <= (new \DateTime('today'))
            ->modify('+'.self::EXTENSION_LOOKAHEAD_MONTHS.' months');
    }

    /**
     * The register rows a human logged are theirs to change from the
     * register itself — a wrong row used to mean finding the decision in
     * a different module by eye.
     *
     * @return array<int, array{url: string, icon: string, title: string, method?: string, confirm?: string}>
     */
    private function leaseDecisionRowActions(LeaseDecision $decision, bool $canEdit, bool $canDelete): array
    {
        $actions = [];

        if ($canEdit) {
            $actions[] = [
                'url' => route('lease-decisions.edit', $decision->id),
                'icon' => 'pencil',
                'title' => trans('general.update'),
            ];
        }

        if ($canDelete) {
            $actions[] = [
                'url' => route('lease-decisions.destroy', $decision->id),
                'icon' => 'trash',
                'title' => trans('general.delete'),
                'method' => 'DELETE',
                'confirm' => trans('general.sure_to_delete_var', ['item' => $decision->contract_reference]),
            ];
        }

        return $actions;
    }

    /**
     * Asset Lease Detail — the full per-asset roll-up that the
     * sharepoint.csv export gives. Lives in /reports/procurement so the
     * same data is available internally without having to open the
     * SharePoint workbook. One row per leased asset, with finance,
     * lifecycle and usage columns.
     */
    private function assetLeaseDetailReport(?string $fy = null): array
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

        $assets = $this->scopeDateToFiscalYear(
            Asset::with('model', 'status', 'assignedTo')
                ->whereNotNull($contractIdColumn)
                ->where($contractIdColumn, '!=', ''),
            $fy,
            'purchase_date'
        )
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
                'links' => array_filter([
                    0 => route('hardware.show', $asset->id),
                    1 => route('hardware.show', $asset->id),
                    6 => $this->assignedTarget($asset) instanceof User
                        ? route('users.show', $this->assignedTarget($asset)->id) : null,
                ]),
                'cells' => [
                    (string) $asset->asset_tag,
                    (string) $asset->serial,
                    (string) $asset->status?->name,
                    (string) $asset->model?->name,
                    $cols['usage'] ? (string) $asset->{$cols['usage']} : '',
                    $cols['area'] ? (string) $asset->{$cols['area']} : '',
                    (string) $this->describeAssignedTo($this->assignedTarget($asset)),
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
    private function poDrilldownReport(?string $fy = null): array
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
            // Attribute by order FY so a blanket PO contributes only the
            // orders booked in the selected year; a null FY is all years.
            $orders = $fy ? $po->orders->where('fiscal_year', $fy) : $po->orders;
            if ($orders->isEmpty()) {
                continue;
            }

            $poVendor = $poExpected = $poVariance = 0.0;
            $poRows = [];

            foreach ($orders as $order) {
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
     * Per-Serial Disposition Grid data, grouped one tab per lease contract
     * — the in-app replacement for the per-contract sheets of the
     * Leases.xlsx workbook. Each contract holds one row per leased serial
     * with the lifecycle columns the workbook carries (status, returned
     * date, usage, ownership, category, model) plus the disposition
     * decision. The decision resolves per serial first (a LeaseDecision
     * tied to this asset), falling back to the contract-level decision when
     * no per-serial call has been logged.
     */
    private function dispositionGridData(): array
    {
        $cols = $this->leaseFieldColumns();
        $contractIdColumn = $cols['contract_id'];
        if (! $contractIdColumn) {
            return ['contracts' => []];
        }

        // Free-text disposition note per device, keyed by asset_id. The
        // disposition itself is NOT entered here — it is read from the asset's
        // own Snipe status + Decommissioned Date (an archived status with a
        // decommission date = the device left our management). The note is just
        // for special cases / buyout justifications.
        $noteByAsset = LeaseDecision::query()
            ->whereNotNull('asset_id')
            ->orderBy('id')
            ->get()
            ->keyBy('asset_id');

        // Every leased device, all fiscal years — the grid mirrors the whole
        // live lease book, not a single year (it replaces the per-contract
        // sheets of the Leases workbook).
        $assets = Asset::with('model.category', 'status', 'lessor', 'assignedTo')
            ->whereNotNull($contractIdColumn)
            ->where($contractIdColumn, '!=', '')
            ->orderBy($contractIdColumn)
            ->orderBy('asset_tag')
            ->get();

        // A handful of schedules never had their name copied onto their
        // devices, so the picker listed them as a bare schedule id while
        // every neighbour read "Devices Leases FY…". The contract register
        // knows the name — fall back to it rather than showing a nameless row.
        $nameBySchedule = Contract::query()
            ->whereNotNull('schedule_number')
            ->where('schedule_number', '!=', '')
            ->pluck('name', 'schedule_number');

        $contracts = [];
        foreach ($assets as $asset) {
            $contractId = $asset->{$contractIdColumn};
            if (! $this->isValidContractId($contractId)) {
                continue;
            }

            if (! isset($contracts[$contractId])) {
                $contracts[$contractId] = [
                    'contract_id' => $contractId,
                    'contract_name' => '',
                    'provider' => $asset->lessor?->name ?: $this->contractProvider($contractId),
                    'lease_end_date' => $cols['lease_end_date'] ? (string) $asset->{$cols['lease_end_date']} : '',
                    'is_lease_to_own' => false,
                    'active_count' => 0,
                    'assets' => [],
                ];
            }

            // The display name lives on each device, and not every device on a
            // lease carries it — a returned or archived unit sorting first
            // would otherwise leave the whole contract reading as a bare
            // schedule id. Take the first device that has one; fall back to
            // the contract register only if none do.
            if ($contracts[$contractId]['contract_name'] === '') {
                $contracts[$contractId]['contract_name'] = ($cols['contract_name']
                    ? trim((string) $asset->{$cols['contract_name']})
                    : '') ?: '';
            }

            $ownership = $cols['ownership_type'] ? (string) $asset->{$cols['ownership_type']} : '';
            if ($ownership === 'Lease to Own') {
                $contracts[$contractId]['is_lease_to_own'] = true;
            }

            // Archived status (with the decommission date) = the device has
            // been disposed (returned / donated / recycled / bought out) and is
            // no longer managed by us. Anything else is still on lease.
            $isArchived = $asset->status?->getStatuslabelType() === 'archived';
            if (! $isArchived) {
                $contracts[$contractId]['active_count']++;
            }

            $buyoutCost = $cols['buyout_cost'] ? $this->parseMoney($asset->{$cols['buyout_cost']}) : 0.0;

            // Who holds the device. A lease row without this reads as an
            // anonymous serial, which is the thing that makes a return or
            // buyout decision impossible to act on — the target can be a
            // person, a room, or another asset, so carry the flavour too.
            $assignedTo = $this->assignedTarget($asset);

            $contracts[$contractId]['assets'][] = [
                'asset_id' => $asset->id,
                'asset_tag' => (string) $asset->asset_tag,
                'serial' => (string) $asset->serial,
                'assigned_name' => $this->describeAssignedTo($assignedTo),
                'assigned_kind' => match (true) {
                    $assignedTo instanceof User => 'user',
                    $assignedTo instanceof Asset => 'asset',
                    $assignedTo instanceof Location => 'location',
                    default => '',
                },
                'assigned_url' => match (true) {
                    $assignedTo instanceof User => route('users.show', $assignedTo->id),
                    $assignedTo instanceof Asset => route('hardware.show', $assignedTo->id),
                    $assignedTo instanceof Location => route('locations.show', $assignedTo->id),
                    default => '',
                },
                'status' => (string) $asset->status?->name,
                'status_id' => $asset->status_id,
                'status_type' => $asset->status?->getStatuslabelType(),
                'archived' => $isArchived,
                'buyout_cost_raw' => $buyoutCost,
                'decommissioned_date' => $cols['decommission_date'] ? $this->dateString($asset->{$cols['decommission_date']}) : '',
                'use' => $cols['usage'] ? $this->useLabel($asset->{$cols['usage']}) : '',
                'ownership' => $ownership,
                'category' => (string) $asset->model?->category?->name,
                'model' => (string) $asset->model?->name,
                'buyout_cost' => $buyoutCost > 0 ? $this->money($buyoutCost) : '',
                'note' => (string) $noteByAsset->get($asset->id)?->notes,
            ];
        }

        // Only contracts that still have at least one on-lease (non-archived)
        // device — fully-returned/closed leases drop off.
        $contracts = array_filter($contracts, fn ($c) => $c['active_count'] > 0);

        // Last resort for a lease whose devices all lack the name.
        foreach ($contracts as $id => $contract) {
            if ($contract['contract_name'] === '') {
                $contracts[$id]['contract_name'] = (string) $nameBySchedule->get($id, '');
            }
        }

        // Soonest lease end first so the contracts nearing term surface first.
        uasort($contracts, fn ($a, $b) => [$a['lease_end_date'], $a['contract_id']] <=> [$b['lease_end_date'], $b['contract_id']]);

        return ['contracts' => array_values($contracts)];
    }

    /**
     * Flatten the disposition grid to a single CSV table (one row per
     * serial across every contract) for the download / hand-off path.
     */
    private function dispositionGridCsv(?string $onlyContract = null): array
    {
        $columns = $this->dispositionGridColumns();

        $records = [];
        foreach ($this->scopedDispositionContracts($onlyContract) as $contract) {
            foreach ($contract['assets'] as $row) {
                $records[] = ['class' => '', 'cells' => $this->dispositionGridRow($contract['contract_id'], $row)];
            }
        }

        return ['columns' => $columns, 'records' => $records, 'footer' => null];
    }

    /**
     * Column headers for the flat disposition exports (CSV + the per-contract
     * xlsx sheets). Buyout Cost sits right after the Decommissioned Date so
     * the lifecycle reads left-to-right (returned → what it cost to keep);
     * the per-contract xlsx omits the leading Lease Contract ID since the
     * sheet name already carries it.
     */
    private function dispositionGridColumns(bool $withContract = true): array
    {
        return array_values(array_filter([
            $withContract ? trans('admin/purchase-orders/general.lease_contract_id') : null,
            trans('admin/purchase-orders/general.detail_serial'),
            trans('admin/purchase-orders/general.detail_asset_tag'),
            trans('admin/purchase-orders/general.disposition_assigned_to'),
            trans('admin/purchase-orders/general.disposition_action'),
            trans('admin/purchase-orders/general.disposition_decommissioned_date'),
            trans('admin/purchase-orders/general.detail_buyout_cost'),
            trans('admin/purchase-orders/general.disposition_use'),
            trans('admin/purchase-orders/general.detail_ownership'),
            trans('general.category'),
            trans('admin/purchase-orders/general.detail_model'),
            trans('general.notes'),
        ], fn ($v) => $v !== null));
    }

    /**
     * One flat row of disposition cells in the same column order as
     * dispositionGridColumns().
     */
    private function dispositionGridRow(string $contractId, array $row, bool $withContract = true): array
    {
        return array_values(array_filter([
            'contract' => $withContract ? $contractId : null,
            'serial' => $row['serial'],
            'asset_tag' => $row['asset_tag'],
            'assigned_to' => $row['assigned_name'],
            'status' => $row['status'],
            'decommissioned_date' => $row['decommissioned_date'],
            'buyout_cost' => $row['buyout_cost'],
            'use' => $row['use'],
            'ownership' => $row['ownership'],
            'category' => $row['category'],
            'model' => $row['model'],
            'note' => $row['note'],
        ], fn ($v) => $v !== null));
    }

    /**
     * The disposition grid as a multi-sheet .xlsx — one worksheet per lease
     * contract, mirroring the structure of the SharePoint Leases.xlsx
     * workbook this report replaces. Each sheet carries the same columns as
     * the on-screen tab; the contract id is the sheet name, so it is dropped
     * from the columns.
     */
    private function dispositionGridXlsx(?string $onlyContract = null): BinaryFileResponse
    {
        $contracts = $this->scopedDispositionContracts($onlyContract);
        $header = $this->dispositionGridColumns(false);

        $tmp = tempnam(sys_get_temp_dir(), 'lease-disposition-');
        $writer = new Writer;
        $writer->openToFile($tmp);

        if (empty($contracts)) {
            $writer->getCurrentSheet()->setName('Lease Disposition');
            $writer->addRow(Row::fromValues($header));
        } else {
            $usedNames = [];
            foreach (array_values($contracts) as $i => $contract) {
                $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($this->uniqueSheetName($contract['contract_id'], $usedNames));
                $writer->addRow(Row::fromValues($header));
                foreach ($contract['assets'] as $row) {
                    $writer->addRow(Row::fromValues(
                        $this->dispositionGridRow($contract['contract_id'], $row, false)
                    ));
                }
            }
        }

        $writer->close();

        $name = 'lease-disposition-'.($onlyContract !== null ? $onlyContract.'-' : '').date('Y-m-d').'.xlsx';

        return response()->download($tmp, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * The disposition contracts, optionally narrowed to one lease id (the
     * active pane) so the downloads carry only the contract on screen.
     * An unknown id falls back to the full set rather than an empty export.
     */
    private function scopedDispositionContracts(?string $onlyContract): array
    {
        $contracts = $this->dispositionGridData()['contracts'];
        if ($onlyContract === null) {
            return $contracts;
        }

        // Same forgiving resolution as the page: exact id first, then
        // substring, so pre-rename links keep exporting the right lease.
        $scoped = array_values(array_filter(
            $contracts,
            fn ($c) => strcasecmp($c['contract_id'], $onlyContract) === 0
        ));
        if ($scoped === []) {
            $scoped = array_values(array_filter(
                $contracts,
                fn ($c) => stripos($c['contract_id'], $onlyContract) !== false
            ));
        }

        return $scoped !== [] ? $scoped : $contracts;
    }

    /**
     * Excel sheet names are capped at 31 chars, cannot contain : \ / ? * [ ]
     * and must be unique within a workbook. Sanitise and de-duplicate.
     */
    private function uniqueSheetName(string $name, array &$used): string
    {
        $clean = preg_replace('/[:\\\\\/?*\[\]]/', '-', $name);
        $clean = mb_substr($clean, 0, 31);
        if ($clean === '') {
            $clean = 'Sheet';
        }

        $base = $clean;
        $n = 2;
        while (isset($used[mb_strtolower($clean)])) {
            $suffix = '-'.$n++;
            $clean = mb_substr($base, 0, 31 - strlen($suffix)).$suffix;
        }
        $used[mb_strtolower($clean)] = true;

        return $clean;
    }

    /**
     * Credit & Termination Ledger. The lease invoice stream is not just
     * monthly rent — every contract eventually accumulates credit memos
     * and a final termination invoice. Splitting them out lets finance
     * see how much credit is outstanding and confirm the closing
     * termination matches the schedule.
     */
    private function creditTerminationReport(?string $fy = null): array
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

        $invoices = $this->scopeDateToFiscalYear(
            OrderInvoice::with('order.purchaseOrder')
                ->whereIn('invoice_type', ['credit', 'termination', 'buyout']),
            $fy,
            'invoice_date'
        )
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
     * sync function: CSI Leasing handles the 301452-* schedules and CCA
     * Financial the ECI* contracts (the ECI portfolio was sold to CCA
     * Financial in mid-2025 — same contract IDs, new lessor). This is a
     * global portfolio snapshot and is never scoped to a single fiscal
     * year — every lessor's full book is always shown.
     */
    private function lessorBreakdownReport(?string $fy = null): array
    {
        // Portfolio snapshot — ignore any incoming FY scope.
        $fy = null;
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
        foreach ($this->groupedLeaseAssets($fy) as $group) {
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

        // Raw (unformatted) per-lessor series for the hub-section charts.
        // Ownership is one series per ownership type so the mix renders as
        // a stacked bar across lessors.
        $ownershipTypes = [];
        foreach ($byLessor as $data) {
            $ownershipTypes = array_unique(array_merge($ownershipTypes, array_keys($data['ownership'])));
        }
        sort($ownershipTypes);

        $chart = [
            'labels' => array_keys($byLessor),
            'cost' => array_map(fn ($d) => round($d['cost'], 2), array_values($byLessor)),
            'rent' => array_map(fn ($d) => round($d['rent'], 2), array_values($byLessor)),
            'assets' => array_column(array_values($byLessor), 'assets'),
            'active' => array_column(array_values($byLessor), 'active'),
            'buyout' => array_column(array_values($byLessor), 'buyout'),
            'ownership' => array_map(fn ($type) => [
                'label' => $type,
                'data' => array_map(fn ($d) => (int) ($d['ownership'][$type] ?? 0), array_values($byLessor)),
            ], $ownershipTypes),
            'annualRent' => $this->annualLeaseRentByFy(),
        ];

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer, 'chart' => $chart];
    }

    /**
     * Annual leasing cost per fiscal year, across every lease. Each contract
     * contributes its monthly basis for the months of its term that fall in
     * each FY: the summed Lease Rent when every device carries one, else the
     * contract's total cost amortised over the 48/60-month convention — the
     * same basis rule the Extension Watch applies, and the label says which
     * precision to expect. The window is bounded around the current FY so a
     * stray far-future device date cannot stretch the axis for a decade.
     */
    private function annualLeaseRentByFy(): array
    {
        $currentStartYear = (int) (now()->month >= 4 ? now()->year : now()->year - 1);
        $fyOf = function (\DateTimeInterface $date) {
            $y = (int) $date->format('Y');
            $startYear = (int) $date->format('n') >= 4 ? $y : $y - 1;

            return sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);
        };

        $byFy = [];
        foreach ($this->groupedLeaseAssets(null) as $group) {
            $basis = $this->leaseRentBasis($group);
            if ($basis === null) {
                continue;
            }

            for ($month = $basis['start']->copy()->startOfMonth(); $month->lessThan($basis['end']); $month->addMonth()) {
                $startYear = (int) ($month->month >= 4 ? $month->year : $month->year - 1);
                if ($startYear < $currentStartYear - 6 || $startYear > $currentStartYear + 6) {
                    continue;
                }
                $fy = $fyOf($month);
                $byFy[$fy] = ($byFy[$fy] ?? 0.0) + $basis['monthly'];
            }
        }

        ksort($byFy);

        return [
            'labels' => array_keys($byFy),
            'data' => array_map(fn ($v) => round($v, 2), array_values($byFy)),
        ];
    }

    /**
     * One contract's rent basis: when its term runs and what a month of it
     * costs. The summed Lease Rent when every device carries one, else the
     * contract's total cost amortised over the actual term — the same basis
     * rule the Extension Watch applies, with `estimated` saying which was
     * used. Null when the term cannot be established at all.
     *
     * Shared by the Annual Rent chart and the Rent Costs table so a bar on
     * one and a total on the other can never disagree.
     *
     * @param  array<string, mixed>  $group
     * @return array{start: Carbon, end: Carbon, monthly: float, estimated: bool}|null
     */
    private function leaseRentBasis(array $group): ?array
    {
        $start = null;
        foreach ($group['assets'] as $asset) {
            if ($asset->purchase_date) {
                $purchase = $asset->purchase_date instanceof \DateTimeInterface
                    ? Carbon::instance(new \DateTime($asset->purchase_date->format('Y-m-d')))
                    : Carbon::parse((string) $asset->purchase_date);
                if ($start === null || $purchase->lessThan($start)) {
                    $start = $purchase;
                }
            }
        }
        if ($start === null) {
            return null;
        }

        $isLeaseToOwn = ! empty($group['ownership_counts']['Lease to Own']);
        $termMonths = $isLeaseToOwn ? 60 : 48;

        $end = null;
        if (! empty($group['lease_end_date'])) {
            foreach (['Y-m-d', 'm/d/Y'] as $fmt) {
                $parsed = \DateTime::createFromFormat($fmt, $group['lease_end_date']);
                if ($parsed !== false) {
                    $end = Carbon::instance($parsed);
                    break;
                }
            }
        }
        $end ??= $start->copy()->addMonths($termMonths);
        if ($end->lessThanOrEqualTo($start)) {
            return null;
        }

        $rentIsComplete = $group['monthly_rent_total'] > 0
            && $this->assetsWithRent($group['assets']) === count($group['assets']);
        $actualTermMonths = max(1, (int) round($start->diffInDays($end) / 30.44));

        return [
            'start' => $start,
            'end' => $end,
            'monthly' => $rentIsComplete
                ? (float) $group['monthly_rent_total']
                : (float) $group['total_cost'] / $actualTermMonths,
            'estimated' => ! $rentIsComplete,
        ];
    }

    /**
     * Rent Costs — what one fiscal year's leasing costs, contract by
     * contract. The Annual Rent chart answers "how much a year"; the first
     * follow-up is always "made up of what", and answering it meant opening
     * the SharePoint workbook. Each contract contributes its monthly basis
     * for the months of its term that fall inside the year.
     */
    private function rentCostsReport(?string $fy = null): array
    {
        // The question is "what does this year's leasing cost" — an
        // unscoped register answers a different one, so no selection means
        // the current fiscal year, never "all".
        $startYear = (int) (now()->month >= 4 ? now()->year : now()->year - 1);
        if ($fy && preg_match('/^FY(\d{4})-\d{2}$/', $fy, $m)) {
            $startYear = (int) $m[1];
        }
        $fyLabel = sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);
        $fyStart = Carbon::create($startYear, 4, 1);
        $fyEnd = $fyStart->copy()->addYear();

        $columns = [
            trans('admin/purchase-orders/general.lease_contract_name'),
            trans('admin/purchase-orders/general.lease_provider'),
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.rent_fy_total', ['fy' => $fyLabel]),
            trans('admin/purchase-orders/general.lease_end_date'),
            trans('admin/purchase-orders/general.lease_ownership'),
        ];

        $records = [];
        $total = 0.0;

        foreach ($this->groupedLeaseAssets(null) as $group) {
            $basis = $this->leaseRentBasis($group);
            if ($basis === null) {
                continue;
            }

            // The same month walk as the Annual Rent chart, restricted to
            // the chosen year, so the table's total IS that year's bar.
            $months = 0;
            for ($month = $basis['start']->copy()->startOfMonth(); $month->lessThan($basis['end']); $month->addMonth()) {
                if ($month->greaterThanOrEqualTo($fyStart) && $month->lessThan($fyEnd)) {
                    $months++;
                }
            }
            if ($months === 0) {
                continue;
            }

            $fyRent = $basis['monthly'] * $months;
            $total += $fyRent;

            $records[] = [
                'class' => '',
                'cells' => [
                    $group['contract_name'] ?: $group['contract_id'],
                    $group['provider'],
                    $group['contract_id'],
                    $this->money($fyRent)
                        .($basis['estimated'] ? ' '.trans('admin/purchase-orders/general.extension_cost_estimated') : ''),
                    $this->dateString($group['lease_end_date']),
                    $this->summariseCounts($group['ownership_counts']),
                ],
                // Both name and id open the lease's own page.
                'links' => [
                    0 => route('reports.procurement.lease-detail', $group['contract_id']),
                    2 => route('reports.procurement.lease-detail', $group['contract_id']),
                ],
            ];
        }

        // Contract name first, naturally ordered so "#2" sorts before "#10".
        // The table is sortable in the browser, so this is the resting order
        // rather than the only one: the register reads as a list of contracts,
        // and biggest-line-first is one click on the rent column.
        usort($records, fn ($a, $b) => strnatcasecmp((string) $a['cells'][0], (string) $b['cells'][0]));

        $footer = [
            trans('admin/orders/general.total'), '', '',
            $this->money($total), '', '',
        ];

        // 'fy' rides along so the page can say which year it resolved to
        // when the caller passed nothing. 'sortable' opts the table into
        // in-browser column sorting, which is safe here because a register of
        // contracts is a page of rows, not a paginated set.
        return ['columns' => $columns, 'records' => $records, 'footer' => $footer, 'fy' => $fyLabel, 'sortable' => true];
    }

    /**
     * PST Applicability. Curriculum-tagged assets are PST-exempt under
     * BC's school-supplies exemption; Admin-tagged assets are not. Per
     * contract: split the dollar value between exempt and taxable and
     * compute the estimated PST exposure (7% of the taxable share).
     */
    private function pstApplicabilityReport(?string $fy = null): array
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

        foreach ($this->groupedLeaseAssets($fy) as $group) {
            $exemptCost = $taxableCost = 0.0;
            $curriculumCount = $adminCount = 0;

            foreach ($group['assets'] as $asset) {
                $cost = (float) $asset->purchase_cost;
                $usage = $cols['usage'] ? (string) $asset->{$cols['usage']} : '';

                if ($this->useClass($usage) === 'curriculum') {
                    $exemptCost += $cost;
                    $curriculumCount++;
                } elseif ($this->useClass($usage) === 'admin') {
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
    /**
     * The checkout target of an asset, eager-load safe.
     *
     * Asset::assignedTo() is `morphTo('assigned', …)`, so the relation's own
     * name is `assigned` while the method is `assignedTo`. Eager loading with
     * `with('assignedTo')` therefore lands the resolved target under the
     * `assigned` key and leaves `assignedTo` holding null — read the property
     * after eager loading and every row comes back unassigned. Prefer the
     * loaded relation, fall back to a lazy read.
     */
    private function assignedTarget(Asset $asset)
    {
        if (! $asset->assigned_to) {
            return null;
        }

        if ($asset->relationLoaded('assigned')) {
            return $asset->getRelation('assigned');
        }

        return $asset->assignedTo;
    }

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
     * Schedule Signing Queue. The chase view the assets team uses when they need to
     * know which lease schedules are still draft / awaiting Viktor +
     * Mark's signature. Default filter is the open stages; `?stage=all`
     * exposes signed / active history too. Each row shows the days
     * pending (so old schedules float to the top) and the vendor-on-hold
     * flag (Apple account on hold pattern).
     */
    private function scheduleSigningQueueReport(?string $stageFilter = null, ?string $fy = null): array
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

        $this->scopeDateToFiscalYear($query, $fy, 'received_at');

        if ($stageFilter === null || $stageFilter === 'open') {
            $query->whereIn('lifecycle_stage', LeaseSchedule::OPEN_STAGES);
        } elseif ($stageFilter !== 'all' && in_array($stageFilter, LeaseSchedule::LIFECYCLE_STAGES, true)) {
            $query->where('lifecycle_stage', $stageFilter);
        }

        $schedules = $query->get();

        // Real-asset counts per schedule_ref via the existing Lease
        // Contract ID custom field — gives the assets team a quick "Annexure says
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

            // > 10 working days on the chase list is the threshold the assets team
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
    private function render(Request $request, string $filename, string $title, string $routeName, array $report, string $controls = '', array $extraParams = [], bool $fyFilterable = false)
    {
        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv($filename, $report);
        }

        // Embed mode returns just the table (no page chrome) so the
        // procurement dashboard can lazy-load every report inline.
        if ($request->boolean('embed')) {
            return $this->embedTable($report);
        }

        $canEditNotes = auth()->user()?->can('create', Order::class) ?? false;

        // When the report honours the fiscal-year scope, keep it on the
        // download link and feed the inline FY selector so the dashboard's
        // selection stays put as the reader pivots and exports.
        $selectedFy = $fyFilterable ? $this->resolveFiscalYear($request) : null;
        $downloadParams = array_merge(['format' => 'csv'], $extraParams);
        if ($fyFilterable) {
            $downloadParams['fiscal_year'] = $request->query('fiscal_year', $selectedFy);
        }

        return view('reports/procurement/show', [
            'reportTitle' => $title,
            'columns' => $report['columns'],
            'rows' => $report['records'],
            'footer' => $report['footer'] ?? null,
            'reportCharts' => $report['charts'] ?? null,
            'nowrapExceptLast' => $report['nowrap_except_last'] ?? false,
            'sortable' => $report['sortable'] ?? false,
            'controls' => $controls,
            'downloadUrl' => route($routeName, array_filter($downloadParams, fn ($v) => $v !== null && $v !== '')),
            'reportParams' => $extraParams,
            'fyFilterable' => $fyFilterable,
            'selectedFy' => $selectedFy,
            'allFiscalYears' => $fyFilterable ? $this->availableFiscalYears() : collect(),
            'canEditNotes' => $canEditNotes,
        ]);
    }

    /**
     * Render just a report's table (no page layout) for inline embedding on
     * the procurement dashboard. Takes the uniform builder shape and feeds
     * the shared `_report-table` partial.
     */
    private function embedTable(array $report)
    {
        return view('reports/procurement/_report-table', [
            'columns' => $report['columns'],
            // A report may render differently from how it exports — Lease
            // Reconciliation folds its matches on screen and lists every one
            // in the CSV.
            'rows' => $report['records_display'] ?? $report['records'],
            'footer' => $report['footer'] ?? null,
            'nowrapExceptLast' => $report['nowrap_except_last'] ?? false,
            'sortable' => $report['sortable'] ?? false,
            'canEditNotes' => auth()->user()?->can('create', Order::class) ?? false,
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

                // Nested unit rows flatten under their parent, indented by
                // one empty cell — the same shape the flat export had.
                foreach ($record['children']['rows'] ?? [] as $child) {
                    fputcsv($handle, $formatter->escapeRecord(array_merge([''], $child['cells'])));
                }
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
     * The fiscal years that carry procurement data, canonicalised and
     * sorted. Drawn from real purchase orders and planned (forecast)
     * orders — the same pool the dashboard FY selector offers.
     */
    private function availableFiscalYears(): Collection
    {
        // Orders (not just POs / planned forecasts) carry their own FY, and a
        // blanket PO's orders can sit in a later year than the PO itself —
        // e.g. schedules 007/008 in FY2026-27 on a FY2025-26 PO. Those years
        // have to be offered, or you couldn't filter to the split-out slice.
        //
        // Budget-allocation and lease-end years are offered too: a year is a
        // planning scope before its first PO lands — it already holds the
        // carried-forward budget and the lease-end pre-approval exposure.
        return PurchaseOrder::whereNotNull('fiscal_year')->distinct()->pluck('fiscal_year')
            ->merge(Order::query()->whereNotNull('fiscal_year')->distinct()->pluck('fiscal_year'))
            ->merge(BudgetAllocation::whereNotNull('fiscal_year')->distinct()->pluck('fiscal_year'))
            ->map(fn ($fy) => $this->normalizeFy($fy))
            ->filter()
            ->merge($this->leaseEndFiscalYears())
            ->unique()->sort()->values();
    }

    /**
     * Fiscal years carrying lease-end exposure, from the distinct Lease
     * End Date values on assets — so a planning year is selectable before
     * any spend is booked into it.
     */
    private function leaseEndFiscalYears(): Collection
    {
        $endDateColumn = $this->leaseFieldColumns()['lease_end_date'];
        if (! $endDateColumn) {
            return collect();
        }

        return Asset::whereNotNull($endDateColumn)
            ->distinct()
            ->pluck($endDateColumn)
            ->map(fn ($date) => $this->fiscalYearFromEndDate($date))
            ->filter();
    }

    /**
     * Resolve the fiscal-year scope for any procurement report. The scope is
     * GLOBAL and sticky: an explicit `?fiscal_year` both scopes this request
     * and persists in the session, so the selection follows the reader across
     * the dashboard and every sub-report (deep links included) with no
     * per-link plumbing. Precedence:
     *   1. `?fiscal_year=<fy>`  — scope + persist
     *   2. `?fiscal_year=all`   — cross-year opt-out + persist
     *   3. session              — the last sticky selection
     *   4. none                 — all years (the dashboard seeds an opening
     *                             default into the session; see index())
     * Returns a canonical FY string, or null for "all years".
     */
    private function resolveFiscalYear(Request $request): ?string
    {
        $available = $this->availableFiscalYears();
        $raw = $request->query('fiscal_year');

        if ($raw !== null) {
            if ($raw === 'all') {
                $request->session()->put('procurement.fiscal_year', 'all');

                return null;
            }

            $normalized = $this->normalizeFy($raw);
            if ($normalized && $available->contains($normalized)) {
                $request->session()->put('procurement.fiscal_year', $normalized);

                return $normalized;
            }
        }

        // No selection this request — reuse the sticky session scope. With no
        // session either, fall through to all-years; the dashboard establishes
        // the opening default (latest FY with spend) and persists it so the
        // scope still flows to every report from there.
        $stored = $request->session()->get('procurement.fiscal_year');
        if ($stored === 'all') {
            return null;
        }
        if ($stored !== null && $available->contains($stored)) {
            return $stored;
        }

        return null;
    }

    /**
     * The most recent fiscal year carrying committed spend — the opening
     * scope before the reader picks one. Falls back to the latest available
     * FY, then null (all-years) when there's no procurement data at all.
     */
    private function defaultFiscalYear(Collection $available): ?string
    {
        foreach ($available->sortDesc()->values() as $fy) {
            // Asset-sourced committed (equipment + warranty) is the same figure
            // the dashboard headlines, so the opening year matches what's shown.
            if (array_sum($this->assetCommittedByPo($fy)) > 0) {
                return $fy;
            }
        }

        return $available->last();
    }

    /**
     * Canonicalise a fiscal-year label to the four-digit-start `FY2025-26`
     * shape used by orders and purchase orders. Accepts the two-digit
     * `FY25-26` form the lease end-date helper historically emitted, plus
     * loose `2025-26` / `2025` inputs. Returns null for empty or
     * unrecognised values.
     *
     * This is the seam that lets order-driven data (committed/invoiced,
     * keyed `FY2025-26`) and lease-end data (historically keyed `FY25-26`)
     * line up on the same axis instead of silently missing each other.
     */
    private function normalizeFy(?string $fy): ?string
    {
        if ($fy === null) {
            return null;
        }

        $fy = trim($fy);
        if ($fy === '' || strtolower($fy) === 'all') {
            return null;
        }

        // Four-digit start: `FY2025-26` / `2025-26`.
        if (preg_match('/(\d{4})\s*-\s*(\d{2})$/', $fy, $m)) {
            return 'FY'.$m[1].'-'.$m[2];
        }

        // Two-digit start: `FY25-26` -> `FY2025-26`.
        if (preg_match('/(\d{2})\s*-\s*(\d{2})$/', $fy, $m)) {
            return 'FY20'.$m[1].'-'.$m[2];
        }

        // Bare start year: `2025` -> `FY2025-26`.
        if (preg_match('/(\d{4})$/', $fy, $m)) {
            $start = (int) $m[1];

            return 'FY'.$start.'-'.substr((string) ($start + 1), -2);
        }

        return null;
    }

    /**
     * The start calendar year of a canonical `FY2025-26` label (2025), or
     * null if it can't be parsed. ECU fiscal years run April-March, so
     * FY2025-26 spans 2025-04-01 to 2026-03-31.
     */
    private function fiscalYearStartYear(?string $fy): ?int
    {
        $fy = $this->normalizeFy($fy);
        if ($fy === null) {
            return null;
        }

        return (int) substr($fy, 2, 4);
    }

    /**
     * The [start, end] Carbon bounds of a fiscal year (April 1 -> March 31),
     * or null for an unparseable / "all" FY. Used to constrain reports that
     * attribute by a date column (asset purchase / EOL, decision date,
     * schedule received) rather than by an order relation.
     */
    private function fiscalYearRange(?string $fy): ?array
    {
        $startYear = $this->fiscalYearStartYear($fy);
        if ($startYear === null) {
            return null;
        }

        return [
            Carbon::create($startYear, 4, 1)->startOfDay(),
            Carbon::create($startYear + 1, 3, 31)->endOfDay(),
        ];
    }

    /**
     * Constrain a query to a fiscal year by one of its own date columns
     * (purchase_date, asset_eol_date, decision_date, received_at, …). A null
     * FY is a no-op. Rows with a null date are dropped when an FY is set,
     * since they can't be attributed to a year.
     */
    private function scopeDateToFiscalYear($query, ?string $fy, string $column)
    {
        $range = $this->fiscalYearRange($fy);

        return $query->when($range, fn ($q) => $q->whereBetween($column, $range));
    }

    /**
     * Scope a query over OrderInvoice to a fiscal year — see
     * OrderInvoice::scopeForFiscalYear for the rule and why it lives on the
     * model. Kept as a thin pass-through so the report builders below read the
     * same as the rest of their FY scoping.
     */
    private function scopeInvoiceToFiscalYear($query, ?string $fy)
    {
        return $query->forFiscalYear($fy);
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
