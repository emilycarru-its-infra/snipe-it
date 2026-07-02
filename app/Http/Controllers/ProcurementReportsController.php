<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\BudgetAllocation;
use App\Models\Category;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Manufacturer;
use App\Models\UserAgreement;
use App\Models\LeaseDecision;
use App\Models\LeaseSchedule;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AssetCommitted;
use App\Services\BudgetCarry;
use App\Services\CsiReconciliation;
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
        $eolAssets = Asset::whereNotNull('asset_eol_date')
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

        $pendingDecisionCount = LeaseDecision::whereNull('asset_id')->where('status', 'pending')->count();

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
            'eolEstimate' => (float) $eolAssets->sum('purchase_cost'),
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
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

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

    public function refreshForecast(Request $request)
    {
        $this->authorize('reports.procurement.view');

        $fy = $this->resolveFiscalYear($request);

        // Early-renewal slotting: any criteria the reader adds (category,
        // ownership type, lease contract, status, …) drives the candidate
        // set instead of the default end-of-life window, so a subset of an
        // active contract can be forecasted for an early refresh — e.g.
        // "Category: Laptop AND Ownership Type: Lease to Own AND Lease
        // Contract ID: ECI20221001". Selected rows still flow through the
        // same planned-order path as the EOL forecast.
        $criteria = $this->forecastCriteria($request);
        $report = $this->refreshForecastReport($fy, $criteria);

        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv('refresh-forecast-report', $report);
        }

        if ($request->boolean('embed')) {
            return $this->embedTable($report);
        }

        return view('reports/procurement/forecast', [
            'reportTitle' => trans('admin/purchase-orders/general.report_forecast'),
            'columns' => $report['columns'],
            'rows' => $report['records'],
            'footer' => $report['footer'] ?? null,
            'downloadUrl' => route('reports.procurement.forecast', array_filter(
                ['format' => 'csv', 'fiscal_year' => $request->query('fiscal_year', $fy), 'criteria' => $criteria],
                fn ($v) => $v !== null && $v !== '' && $v !== []
            )),
            'canCreate' => Gate::allows('create', Order::class),
            'fyFilterable' => true,
            'selectedFy' => $fy,
            'allFiscalYears' => $this->availableFiscalYears(),
            'reportParams' => [],
            'filterFields' => $this->forecastFilterFields(),
            'filterValues' => $this->forecastFilterValues(),
            'activeCriteria' => $criteria,
            'earlyRenewalMode' => $criteria !== [],
        ]);
    }

    /**
     * Normalise the `criteria[]` query input into a clean list of
     * {field, value} predicates, dropping blank rows and any field that is
     * not in the allow-list (the static columns + the live custom fields).
     * All predicates are ANDed in refreshForecastReport().
     */
    private function forecastCriteria(Request $request): array
    {
        $raw = $request->query('criteria', []);
        if (! is_array($raw)) {
            return [];
        }

        $allowed = $this->forecastFilterFields();
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $field = (string) ($row['field'] ?? '');
            $value = trim((string) ($row['value'] ?? ''));
            if ($field === '' || $value === '' || ! isset($allowed[$field])) {
                continue;
            }
            $out[] = ['field' => $field, 'value' => $value];
        }

        return $out;
    }

    /**
     * The fields a forecast criterion can target: a curated set of asset
     * taxonomies plus every custom field (keyed `cf:<db_column>`), so the
     * reader can slot in an early renewal by whatever mix the situation
     * needs without the field list being hard-coded.
     */
    private function forecastFilterFields(): array
    {
        $fields = [
            'category' => trans('general.category'),
            'manufacturer' => trans('general.manufacturer'),
            'model' => trans('general.asset_model'),
            'status' => trans('general.status'),
            'supplier' => trans('general.supplier'),
            'company' => trans('general.company'),
        ];

        foreach (CustomField::orderBy('name')->get() as $field) {
            if ($field->db_column) {
                $fields['cf:'.$field->db_column] = $field->name;
            }
        }

        return $fields;
    }

    /**
     * Known values per field, used to populate the criteria builder's
     * datalists so the reader can autocomplete instead of guessing exact
     * spellings. Custom-field values are sourced from the assets that carry
     * them (capped, since some fields are high-cardinality); Model is left
     * free-text for the same reason.
     */
    private function forecastFilterValues(): array
    {
        $values = [
            'category' => Category::where('category_type', 'asset')->orderBy('name')->pluck('name')->all(),
            'manufacturer' => Manufacturer::orderBy('name')->pluck('name')->all(),
            'status' => Statuslabel::orderBy('name')->pluck('name')->all(),
            'supplier' => Supplier::orderBy('name')->pluck('name')->all(),
            'company' => Company::orderBy('name')->pluck('name')->all(),
        ];

        foreach (CustomField::orderBy('name')->get() as $field) {
            if (! $field->db_column) {
                continue;
            }
            $values['cf:'.$field->db_column] = Asset::query()
                ->whereNotNull($field->db_column)
                ->where($field->db_column, '!=', '')
                ->distinct()
                ->orderBy($field->db_column)
                ->limit(200)
                ->pluck($field->db_column)
                ->all();
        }

        return $values;
    }

    /**
     * Apply one forecast criterion to the asset query. Relation fields match
     * by name; custom fields match the generated column exactly. The column
     * is validated against the live custom-field allow-list before it ever
     * reaches the query builder, so an arbitrary `cf:` value can't inject a
     * column name.
     */
    private function applyForecastCriterion($query, string $field, string $value): void
    {
        switch ($field) {
            case 'category':
                $query->whereHas('model.category', fn ($q) => $q->where('name', $value));
                break;
            case 'manufacturer':
                $query->whereHas('model.manufacturer', fn ($q) => $q->where('name', $value));
                break;
            case 'model':
                $query->whereHas('model', fn ($q) => $q->where('name', $value));
                break;
            case 'status':
                $query->whereHas('status', fn ($q) => $q->where('name', $value));
                break;
            case 'supplier':
                $query->whereHas('supplier', fn ($q) => $q->where('name', $value));
                break;
            case 'company':
                $query->whereHas('company', fn ($q) => $q->where('name', $value));
                break;
            default:
                if (str_starts_with($field, 'cf:')) {
                    $column = substr($field, 3);
                    $allowed = CustomField::pluck('db_column')->filter()->all();
                    if (in_array($column, $allowed, true)) {
                        $query->where($column, $value);
                    }
                }
        }
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

    public function receiving(Request $request): StreamedResponse
    {
        $this->authorize('reports.procurement.view');

        return $this->streamReportCsv('receiving-status-report', $this->receivingReport($this->resolveFiscalYear($request)));
    }

    public function leasesOperational(Request $request)
    {
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

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

    public function csiSchedule(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'csi-schedule-reconciliation-report',
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
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'csi-reconciliation-report',
            trans('admin/purchase-orders/general.report_csi_reconciliation'),
            'reports.procurement.csi-reconciliation',
            $this->csiReconciliationReport()
        );
    }

    private function csiReconciliationReport(): array
    {
        $t = fn ($k) => trans('admin/purchase-orders/general.'.$k);

        $columns = [
            $t('csi_recon_status'), $t('csi_recon_serial'), $t('csi_recon_model'),
            $t('csi_recon_csi_schedule'), $t('csi_recon_snipe_schedule'),
            $t('csi_recon_snipe_tag'), $t('csi_recon_snipe_status'),
        ];

        $records = [];
        $tally = ['match' => 0, 'schedule_mismatch' => 0, 'missing_in_snipe' => 0, 'extra_in_snipe' => 0];

        foreach ((new CsiReconciliation)->assetDiff() as $row) {
            $tally[$row['status']] = ($tally[$row['status']] ?? 0) + 1;
            $records[] = [
                'class' => $row['status'] === 'match' ? '' : 'danger',
                'cells' => [
                    $t('csi_recon_'.$row['status']),
                    $row['serial'],
                    $row['model'],
                    $row['csi_schedule'],
                    $row['snipe_schedule'],
                    $row['snipe_tag'],
                    $row['snipe_status'],
                ],
            ];
        }

        $summary = $tally['match'].' '.$t('csi_recon_match').' · '
            .$tally['schedule_mismatch'].' '.$t('csi_recon_schedule_mismatch').' · '
            .$tally['missing_in_snipe'].' '.$t('csi_recon_missing_in_snipe').' · '
            .$tally['extra_in_snipe'].' '.$t('csi_recon_extra_in_snipe');

        return [
            'columns' => $columns,
            'records' => $records,
            'footer' => [$summary, '', '', '', '', '', ''],
        ];
    }

    /**
     * CSI in-process devices (ordered/shipped, not yet accepted onto a
     * schedule) and whether Snipe already knows each one — the "what's
     * arriving" view for receiving / deployment planning.
     */
    public function csiArrivals(Request $request)
    {
        $this->authorize('reports.procurement.view');

        return $this->render(
            $request,
            'csi-arrivals-report',
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
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

        $typeFilter  = $request->query('agreement_type');
        $stageFilter = $request->query('stage');
        $fy          = $this->resolveFiscalYear($request);
        $report      = $this->userAgreementLedgerReport($typeFilter, $stageFilter, $fy);

        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv('user-agreement-ledger', $report);
        }

        if ($request->boolean('embed')) {
            return $this->embedTable($report);
        }

        // FY scopes by the agreement's origination (created_at) — when the
        // top-up / buyout entered the program.
        $agreements = $this->scopeDateToFiscalYear(
            \App\Models\UserAgreement::with('user', 'asset')
                ->orderByRaw(...$this->fieldOrder('lifecycle_stage', [
                    'eligible', 'quoted', 'agreement_sent', 'agreement_signed',
                    'deployed', 'in_repayment', 'paid_off', 'closed_buyout', 'closed', 'cancelled',
                ]))
                ->orderBy('updated_at', 'desc')
                ->when($typeFilter && in_array($typeFilter, \App\Models\UserAgreement::AGREEMENT_TYPES, true),
                    fn ($q) => $q->where('agreement_type', $typeFilter))
                ->when($stageFilter && in_array($stageFilter, \App\Models\UserAgreement::LIFECYCLE_STAGES, true),
                    fn ($q) => $q->where('lifecycle_stage', $stageFilter)),
            $fy,
            'created_at'
        )->get();

        return view('reports.procurement.user-agreement-ledger', [
            'reportTitle'    => trans('admin/purchase-orders/general.report_user_agreement_ledger'),
            'agreements'     => $agreements,
            'typeFilter'     => $typeFilter,
            'stageFilter'    => $stageFilter,
            'selectedFy'     => $fy,
            'allFiscalYears' => $this->availableFiscalYears(),
            'downloadUrl'    => route('reports.procurement.user-agreement-ledger', array_filter([
                'format'         => 'csv',
                'agreement_type' => $typeFilter,
                'stage'          => $stageFilter,
                'fiscal_year'    => $request->query('fiscal_year', $fy),
            ], fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    public function scheduleSigningQueue(Request $request)
    {
        $this->authorize('reports.procurement.view');

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
            $this->leaseDecisionsReport($request->query('status'), $this->resolveFiscalYear($request)),
            '',
            array_filter(['status' => $request->query('status')]),
            true
        );
    }

    public function poDisposition(Request $request)
    {
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

        // CSV hand-off flattens every contract's serials into one table.
        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv('disposition-grid-report', $this->dispositionGridCsv());
        }

        $data = $this->dispositionGridData();
        $canEdit = auth()->user()?->can('create', \App\Models\Order::class) ?? false;

        $viewData = [
            'contracts' => $data['contracts'],
            'canEdit' => $canEdit,
            'downloadUrl' => route('reports.procurement.disposition-grid', ['format' => 'csv']),
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
        $this->authorize('create', \App\Models\Order::class);

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
     * Inline save of a note on a report row. Generic so any procurement
     * report table can expose an editable (pencil) note cell — the model is
     * whitelisted and the only field touched is `notes`.
     */
    public function updateReportNote(Request $request)
    {
        $this->authorize('create', \App\Models\Order::class);

        $validated = $request->validate([
            'model' => 'required|string|in:lease_decision',
            'id' => 'required|integer',
            'notes' => 'nullable|string|max:65535',
        ]);

        $model = match ($validated['model']) {
            'lease_decision' => LeaseDecision::findOrFail($validated['id']),
            default => abort(422),
        };

        $model->notes = $validated['notes'] ?? '';
        $model->save();

        return response()->json(['status' => 'success', 'notes' => (string) $model->notes]);
    }

    public function creditTerminationLedger(Request $request)
    {
        $this->authorize('reports.procurement.view');

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
        $this->authorize('reports.procurement.view');

        // Lessor Breakdown is a global portfolio snapshot — never FY-scoped.
        return $this->render(
            $request,
            'lessor-breakdown-report',
            trans('admin/purchase-orders/general.report_lessor_breakdown'),
            'reports.procurement.lessor-breakdown',
            $this->lessorBreakdownReport(null),
            '',
            [],
            false
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
            $this->pstApplicabilityReport($this->resolveFiscalYear($request)),
            '',
            [],
            true
        );
    }

    public function tax(Request $request): StreamedResponse
    {
        $this->authorize('reports.procurement.view');

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
    private function refreshForecastReport(?string $fy = null, array $criteria = []): array
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

        // Default forecast: end-of-life devices in the FY (or the rolling
        // next-12-months window for "all"). Early-renewal mode — any criteria
        // supplied — drops the EOL window entirely and lists every device
        // matching the ANDed predicates, so a subset of an active lease can
        // be slotted in for an early refresh well before its EOL date.
        $range = $this->fiscalYearRange($fy);
        $assets = Asset::with('model', 'supplier', 'status')
            ->when($criteria === [], fn ($query) => $query
                ->whereNotNull('asset_eol_date')
                ->when($range, fn ($q) => $q->whereBetween('asset_eol_date', $range))
                ->when(! $range, fn ($q) => $q->whereBetween('asset_eol_date', [now()->startOfDay(), now()->addYear()])))
            ->when($criteria !== [], function ($query) use ($criteria) {
                foreach ($criteria as $criterion) {
                    $this->applyForecastCriterion($query, $criterion['field'], $criterion['value']);
                }
            })
            ->orderBy('asset_eol_date')
            ->orderBy('purchase_date')
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
            'po_number' => 'PO Number',
            'warranty_cost' => 'Warranty/Soft Cost',
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
            ->whereNotNull($endDateColumn)
            ->where($endDateColumn, '!=', '')
            ->whereNotNull($contractIdColumn)
            ->where($contractIdColumn, '!=', '')
            ->get();

        $decisions = $this->leaseDecisionsByContract();

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
        return LeaseDecision::whereNull('asset_id')
            ->where('status', '!=', 'cancelled')
            ->orderBy('decision_date')
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
            Asset::with('model', 'status', 'lessor')
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
    private function leasesOperationalReport(?string $fy = null): array
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

        foreach ($this->groupedLeaseAssets($fy) as $group) {
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
    private function leasesFinancialReport(?string $fy = null): array
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
    private function csiScheduleReport(?string $fy = null): array
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
                'class' => 'info rpt-subtotal',
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
        $columns = [
            trans('admin/purchase-orders/general.user_agreement_type'),
            trans('admin/purchase-orders/general.user_agreement_member'),
            trans('admin/purchase-orders/general.detail_asset_tag'),
            trans('admin/purchase-orders/general.detail_serial'),
            trans('admin/user-agreements/general.originating_contract'),
            trans('admin/purchase-orders/general.user_agreement_contract_value'),
            trans('admin/purchase-orders/general.user_agreement_stage'),
            trans('admin/purchase-orders/general.user_agreement_signed_at'),
            trans('admin/purchase-orders/general.user_agreement_payroll_at'),
        ];

        $query = UserAgreement::with('user', 'asset')
            ->orderByRaw(...$this->fieldOrder('lifecycle_stage', [
                'eligible', 'quoted', 'agreement_sent', 'agreement_signed',
                'deployed', 'in_repayment', 'paid_off', 'closed_buyout', 'closed', 'cancelled',
            ]))
            ->orderBy('updated_at', 'desc');

        $this->scopeDateToFiscalYear($query, $fy, 'created_at');

        if ($typeFilter && in_array($typeFilter, UserAgreement::AGREEMENT_TYPES, true)) {
            $query->where('agreement_type', $typeFilter);
        }
        if ($stageFilter && in_array($stageFilter, UserAgreement::LIFECYCLE_STAGES, true)) {
            $query->where('lifecycle_stage', $stageFilter);
        }

        $agreements = $query->get();

        $records = [];
        $totalValue = 0.0;

        foreach ($agreements as $agreement) {
            $value = $agreement->contractValue();
            $totalValue += $value;

            $records[] = [
                'class' => '',
                'cells' => [
                    trans('admin/purchase-orders/general.user_agreement_type_value_'.$agreement->agreement_type),
                    (string) ($agreement->user?->full_name ?? '—'),
                    (string) ($agreement->asset?->asset_tag ?? ''),
                    (string) ($agreement->asset?->serial ?? ''),
                    (string) ($agreement->lease_contract ?? ''),
                    $this->money($value),
                    trans('admin/purchase-orders/general.user_agreement_stage_value_'.$agreement->lifecycle_stage),
                    $this->dateString($agreement->signed_at),
                    $this->dateString($agreement->sent_to_payroll_at),
                ],
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '', '', '',
            $this->money($totalValue),
            '', '', '',
        ];

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

        foreach ($this->groupedLeaseAssets($fy) as $group) {
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

            // Only a genuine holdover counts: the original term must already
            // have elapsed. A lease whose original term still has time left is
            // not an extension, however far out its recorded end date is —
            // this drops far-future schedules that merely have an end date a
            // month or two past their computed term end.
            if ($originalEnd >= $now) {
                continue;
            }

            // Months extended = original-term-end → today (the lease is still
            // running), so the figure grows as the holdover drags on rather
            // than reflecting a not-yet-reached recorded end date.
            $extendedTo = $leaseEnd > $now ? $now : $leaseEnd;
            $months = (($extendedTo->format('Y') - $originalEnd->format('Y')) * 12)
                + ((int) $extendedTo->format('m') - (int) $originalEnd->format('m'));

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

        // Lease-to-own contracts carry no retirement obligation — the
        // equipment is simply kept at term end, no buyout/return decision is
        // owed — so they are excluded from the register entirely (both the
        // contractual rows below and any logged decisions further down).
        $leaseToOwnContracts = [];

        // Real per-asset Buyout Cost values aggregated per contract —
        // the contractual obligation regardless of whether the buyout has
        // been booked as a LeaseDecision yet. Only shown when the field
        // contains real numbers.
        foreach ($this->groupedLeaseAssets($fy) as $group) {
            if (! empty($group['ownership_counts']['Lease to Own'])) {
                $leaseToOwnContracts[$group['contract_id']] = true;
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

        foreach ($decisions as $decision) {
            // Skip decisions logged against a lease-to-own contract — keeping
            // lease-to-own equipment is not a retirement obligation.
            if (isset($leaseToOwnContracts[$decision->contract_reference])) {
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
            ];
        }

        $footer = [
            trans('admin/orders/general.total'), '', '',
            $this->money($total), '', '', '',
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
        $assets = Asset::with('model.category', 'status', 'lessor')
            ->whereNotNull($contractIdColumn)
            ->where($contractIdColumn, '!=', '')
            ->orderBy($contractIdColumn)
            ->orderBy('asset_tag')
            ->get();

        $contracts = [];
        foreach ($assets as $asset) {
            $contractId = $asset->{$contractIdColumn};
            if (! $this->isValidContractId($contractId)) {
                continue;
            }

            if (! isset($contracts[$contractId])) {
                $contracts[$contractId] = [
                    'contract_id' => $contractId,
                    'provider' => $asset->lessor?->name ?: $this->contractProvider($contractId),
                    'lease_end_date' => $cols['lease_end_date'] ? (string) $asset->{$cols['lease_end_date']} : '',
                    'is_lease_to_own' => false,
                    'active_count' => 0,
                    'assets' => [],
                ];
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

            $contracts[$contractId]['assets'][] = [
                'asset_id' => $asset->id,
                'asset_tag' => (string) $asset->asset_tag,
                'serial' => (string) $asset->serial,
                'status' => (string) $asset->status?->name,
                'archived' => $isArchived,
                'decommissioned_date' => $cols['decommission_date'] ? $this->dateString($asset->{$cols['decommission_date']}) : '',
                'usage' => $cols['usage'] ? (string) $asset->{$cols['usage']} : '',
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

        // Soonest lease end first so the contracts nearing term surface first.
        uasort($contracts, fn ($a, $b) => [$a['lease_end_date'], $a['contract_id']] <=> [$b['lease_end_date'], $b['contract_id']]);

        return ['contracts' => array_values($contracts)];
    }

    /**
     * Flatten the disposition grid to a single CSV table (one row per
     * serial across every contract) for the download / hand-off path.
     */
    private function dispositionGridCsv(): array
    {
        $columns = [
            trans('admin/purchase-orders/general.lease_contract_id'),
            trans('admin/purchase-orders/general.detail_serial'),
            trans('admin/purchase-orders/general.detail_asset_tag'),
            trans('admin/purchase-orders/general.disposition_action'),
            trans('admin/purchase-orders/general.disposition_decommissioned_date'),
            trans('admin/purchase-orders/general.invoice_usage'),
            trans('admin/purchase-orders/general.detail_ownership'),
            trans('general.category'),
            trans('admin/purchase-orders/general.detail_model'),
            trans('admin/purchase-orders/general.detail_buyout_cost'),
            trans('general.notes'),
        ];

        $records = [];
        foreach ($this->dispositionGridData()['contracts'] as $contract) {
            foreach ($contract['assets'] as $row) {
                $records[] = ['class' => '', 'cells' => [
                    $contract['contract_id'],
                    $row['serial'],
                    $row['asset_tag'],
                    $row['status'],
                    $row['decommissioned_date'],
                    $row['usage'],
                    $row['ownership'],
                    $row['category'],
                    $row['model'],
                    $row['buyout_cost'],
                    $row['note'],
                ]];
            }
        }

        return ['columns' => $columns, 'records' => $records, 'footer' => null];
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

        return ['columns' => $columns, 'records' => $records, 'footer' => $footer];
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

        $canEditNotes = auth()->user()?->can('create', \App\Models\Order::class) ?? false;

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
            'rows'    => $report['records'],
            'footer'  => $report['footer'] ?? null,
            'canEditNotes' => auth()->user()?->can('create', \App\Models\Order::class) ?? false,
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
     * The fiscal years that carry procurement data, canonicalised and
     * sorted. Drawn from real purchase orders and planned (forecast)
     * orders — the same pool the dashboard FY selector offers.
     */
    private function availableFiscalYears(): \Illuminate\Support\Collection
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
    private function leaseEndFiscalYears(): \Illuminate\Support\Collection
    {
        $endDateColumn = $this->leaseFieldColumns()['lease_end_date'];
        if (! $endDateColumn) {
            return collect();
        }

        return Asset::whereNotNull($endDateColumn)
            ->where($endDateColumn, '!=', '')
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
    private function defaultFiscalYear(\Illuminate\Support\Collection $available): ?string
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
            \Carbon\Carbon::create($startYear, 4, 1)->startOfDay(),
            \Carbon\Carbon::create($startYear + 1, 3, 31)->endOfDay(),
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
     * Scope a query over OrderInvoice to a fiscal year by the FY of its
     * booking order — the actual transaction, not the parent PO (a blanket
     * purchase order spans fiscal years, e.g. P0025420 carries schedules
     * 003-006 in FY2025-26 and 007-008 in FY2026-27, so attribution has to
     * follow the order) — but fall back to the invoice's own invoice_date
     * when the order carries no fiscal_year. CDW-ingested orders don't always
     * get a fiscal_year
     * stamped (the webhook used to leave it null), so without the fallback
     * those invoices — e.g. the leased CDW iPads on a CSI schedule — would
     * vanish from the Invoiced tile and the approval queue even though they
     * have a real invoice_date. A null FY is a no-op (all-years).
     */
    private function scopeInvoiceToFiscalYear($query, ?string $fy)
    {
        if (! $fy) {
            return $query;
        }

        $range = $this->fiscalYearRange($fy);

        return $query->where(function ($q) use ($fy, $range) {
            $q->whereHas('order', fn ($o) => $o->where('fiscal_year', $fy));

            if ($range) {
                $q->orWhere(fn ($alt) => $alt
                    ->whereDoesntHave('order', fn ($o) => $o->whereNotNull('fiscal_year')->where('fiscal_year', '!=', ''))
                    ->whereBetween('invoice_date', $range));
            }
        });
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
