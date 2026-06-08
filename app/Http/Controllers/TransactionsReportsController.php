<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Transactions\EffectiveLineItem;
use App\Models\Transactions\GlTotal;
use App\Models\Transactions\LineItem;
use App\Models\Transactions\Override;
use App\Models\Transactions\RawRow;
use App\Models\Transactions\Reconciliation;
use Illuminate\Http\Request;

/**
 * Dashboard for the TouchNet/PaperCut reconciliation pipeline.
 *
 * Reads exclusively from the `transaction_*` tables that the Azure Function
 * App populates. This controller never reaches out to Azure -- if the
 * Function App is down, the dashboard simply shows the last successful
 * roll-up. That keeps the dashboard side fully independent of the
 * SharePoint / blob output of the pipeline.
 *
 * Routes:
 *   GET /reports/transactions                              -> index
 *   GET /reports/transactions/reconciliations              -> reconciliations
 *   GET /reports/transactions/reconciliations/{ym}         -> show
 *   GET /reports/transactions/gl-breakdown                 -> glBreakdown
 *   GET /reports/transactions/mail-room                    -> mailRoom
 *   GET /reports/transactions/refunds                      -> refunds
 *
 * Pattern mirrors ProcurementReportsController. Each method is a thin
 * data-loader plus a `view()` call; no business logic lives here.
 */
class TransactionsReportsController extends Controller
{
    public function index(Request $request)
    {
        // Trailing 12 months of reconciliations, ordered most-recent first.
        $latest = Reconciliation::orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->limit(12)
            ->get();

        // Period selection: explicit ?period=YYYY-MM wins; otherwise pick
        // the newest period that actually has data so an unprocessed
        // current month doesn't render the dashboard as a wall of $0.00.
        $current = $this->pickCurrentReconciliation($latest, $request->query('period'));

        // Headline cards — pulled from the *effective* line items (override
        // wins). Per Carlos's PaperCut_10-2082 Reconcile tab.
        $cards = $current
            ? $this->buildHeadlineCards($current->period_year, $current->period_month)
            : [];

        // Charts: trailing-12 revenue + per-department mix.
        $monthly = $this->monthlySeries($latest);
        $deptMix = $current
            ? $this->departmentMix($current->period_year, $current->period_month)
            : [];

        // Inline widgets — same data the dedicated drill-down pages render,
        // capped to the top rows so the dashboard stays scannable.
        $widgets = $current
            ? $this->buildWidgets($current->period_year, $current->period_month)
            : [];

        return view('reports.transactions.index', [
            'latest'  => $latest,
            'current' => $current,
            'cards'   => $cards,
            'monthly' => $monthly,
            'deptMix' => $deptMix,
            'widgets' => $widgets,
        ]);
    }

    /**
     * Pick which reconciliation drives the dashboard. Priority:
     *   1. Explicit `?period=YYYY-MM` if it matches a known reconciliation.
     *   2. Newest period with non-zero data (so the "wall of $0.00" caused
     *      by an unprocessed current month falls back to the last fully
     *      processed one).
     *   3. Plain newest, if nothing has data yet.
     *   4. null if there are no reconciliations at all.
     */
    private function pickCurrentReconciliation($latest, ?string $periodParam): ?Reconciliation
    {
        if ($latest->isEmpty()) {
            return null;
        }

        if ($periodParam) {
            $explicit = $latest->first(fn ($r) => $r->period_label === $periodParam);
            if ($explicit) {
                return $explicit;
            }
        }

        $firstWithData = $latest->first(function ($r) {
            return EffectiveLineItem::forPeriod($r->period_year, $r->period_month)
                ->where('amount', '<>', 0)
                ->exists();
        });

        return $firstWithData ?? $latest->first();
    }

    private function buildWidgets(int $year, int $month): array
    {
        $glRows = GlTotal::forPeriod($year, $month, 'calendar')
            ->orderByDesc('dollar_total')
            ->limit(8)
            ->get();

        $mailRoom = RawRow::forPeriod($year, $month)
            ->ofKind('papercut.print_logs.mailroom')
            ->orderBy('id')
            ->limit(8)
            ->get();

        // PaperCut's "Transactions by Type" report is already a per-type
        // summary (one row per type code: PRINT, PRINT_REFUND, ADJUST,
        // ADJUST_EXTERNAL, …). Show them all — the widget flags the
        // refund-typed rows visually rather than pre-filtering, because
        // the non-refund rows carry the same kind of operator context.
        $refunds = RawRow::forPeriod($year, $month)
            ->ofKind('papercut.transactions')
            ->orderBy('id')
            ->limit(8)
            ->get();

        $selfServe = EffectiveLineItem::forPeriod($year, $month)
            ->where('line_key', 'like', 'revenue_%')
            ->where('line_key', '<>', 'revenue_papercut')
            ->orderBy('line_key')
            ->get();

        return [
            'gl'        => $glRows,
            'mailroom'  => $mailRoom,
            'refunds'   => $refunds,
            'selfServe' => $selfServe,
        ];
    }

    /**
     * The 6 headline status cards rendered at the top of the dashboard.
     * Order matches the procurement dashboard's pattern: revenue, refunds,
     * balance change, override count, status, reconciling difference.
     */
    private function buildHeadlineCards(int $year, int $month): array
    {
        $lines = EffectiveLineItem::forPeriod($year, $month)->get()
            ->keyBy('line_key');

        $get = fn (string $k) => (float) optional($lines->get($k))->amount;

        $revenue   = $get('pc_self_serve_revenue');
        $refunds   = $get('pc_printing_refunds');
        $startBal  = $get('pc_account_balance_start');
        $endBal    = $get('pc_account_balance_end');
        $autoXfer  = $get('pc_auto_transfers_from_dw');
        $dwDeposits = $get('dw_oneweb_deposits');

        $balanceDelta = $endBal - $startBal;

        // The two cutover-tab reconciling differences — the answer-first
        // numbers Carlos checks the moment a reconciliation runs.
        // PaperCut (10-2082 tab, cell C20): total_transactions - balance_delta.
        $totalTxn = $refunds + $autoXfer + $get('pc_events_funds_added')
                    + $get('pc_manual_misc_to_papercut')
                    + $get('pc_manual_migration_dw_to_pc')
                    - $revenue
                    - $get('pc_manual_migration_pc_to_dw')
                    - $get('pc_manual_misc_from_papercut');
        $pcReconciling = $totalTxn - $balanceDelta;

        // Digital Wallet (10-2081 tab, cell B29). Its rollup (computed ending
        // balance vs the month-end reading) lives in the pipeline's emitter,
        // so we read the value it persists rather than re-deriving here and
        // risking drift from the workbook.
        $dwReconciling = $get('dw_reconciling_difference');

        // Tone: under $1 is penny-parity (matches Carlos's own accepted
        // ~$0.56 residual) → green; a small single/double-digit gap is an
        // explained classification difference → amber; larger → red.
        $reconTone = fn (float $v) => abs($v) < 1.0
            ? 'green'
            : (abs($v) < 25.0 ? 'yellow' : 'red');

        return [
            ['label' => 'Self-Serve Print Revenue', 'value' => $revenue,
             'fmt' => 'money', 'tone' => 'aqua', 'icon' => 'fa-print'],
            ['label' => 'Digital Wallet Deposits',  'value' => $dwDeposits,
             'fmt' => 'money', 'tone' => 'green', 'icon' => 'fa-wallet'],
            ['label' => 'Refunds Posted',           'value' => $refunds,
             'fmt' => 'money', 'tone' => 'yellow', 'icon' => 'fa-undo'],
            ['label' => 'PaperCut Balance Change',  'value' => $balanceDelta,
             'fmt' => 'money', 'tone' => 'blue', 'icon' => 'fa-balance-scale',
             // The opening balance is the prior month's closing user-list
             // snapshot, seeded by the pipeline from the previous period — so
             // it never lives in the current period's own folder. Gate the
             // placeholder on whether that opening balance is actually present
             // (the genuine "first month, no prior data" case), not on an
             // in-period snapshot count, which is structurally always 1 for
             // the current month and wrongly hid a valid balance change.
             'placeholder' => abs($startBal) < 0.01
                ? 'Awaiting prior-month opening balance'
                : null],
            ['label' => 'PaperCut Reconciling (10-2082)', 'value' => $pcReconciling,
             'fmt' => 'money', 'tone' => $reconTone($pcReconciling),
             'icon' => 'fa-check-circle'],
            ['label' => 'Digital Wallet Reconciling (10-2081)', 'value' => $dwReconciling,
             'fmt' => 'money', 'tone' => $reconTone($dwReconciling),
             'icon' => 'fa-balance-scale'],
        ];
    }

    private function monthlySeries($reconciliations): array
    {
        $series = [];
        foreach ($reconciliations->reverse() as $r) {
            $lines = EffectiveLineItem::forPeriod($r->period_year, $r->period_month)
                ->get()->keyBy('line_key');
            $get = fn ($k) => (float) optional($lines->get($k))->amount;
            $series[] = [
                'label'    => $r->period_label,
                'revenue'  => $get('pc_self_serve_revenue'),
                'deposits' => $get('dw_oneweb_deposits'),
                'refunds'  => $get('pc_printing_refunds'),
            ];
        }
        return $series;
    }

    private function departmentMix(int $year, int $month): array
    {
        $rows = EffectiveLineItem::forPeriod($year, $month)
            ->where('line_key', 'like', 'revenue_%')
            ->where('line_key', '<>', 'revenue_papercut')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $label = ucwords(str_replace(['revenue_', '_'], ['', ' '], $r->line_key));
            $out[] = ['label' => $label, 'value' => (float) $r->amount];
        }
        usort($out, fn ($a, $b) => $b['value'] <=> $a['value']);
        return $out;
    }

    public function reconciliations()
    {
        $all = Reconciliation::orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate(24);

        return view('reports.transactions.reconciliations', ['items' => $all]);
    }

    public function show(string $ym)
    {
        [$year, $month] = $this->parseYm($ym);
        $recon = Reconciliation::where('period_year', $year)
            ->where('period_month', $month)
            ->firstOrFail();

        $glCalendar = GlTotal::forPeriod($year, $month, 'calendar')
            ->orderByDesc('dollar_total')
            ->get();
        $glGp = GlTotal::forPeriod($year, $month, 'gp_period')
            ->orderByDesc('dollar_total')
            ->get();

        return view('reports.transactions.show', [
            'recon'      => $recon,
            'glCalendar' => $glCalendar,
            'glGp'       => $glGp,
        ]);
    }

    public function glBreakdown(Request $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));
        $kind = $request->input('kind', 'calendar');

        $rows = GlTotal::forPeriod($year, $month, $kind)
            ->orderByDesc('dollar_total')
            ->get();

        $grand = $rows->sum('dollar_total');

        return view('reports.transactions.gl-breakdown', [
            'rows'  => $rows,
            'grand' => $grand,
            'year'  => $year,
            'month' => $month,
            'kind'  => $kind,
        ]);
    }

    public function mailRoom(Request $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $rows = RawRow::forPeriod($year, $month)
            ->ofKind('papercut.print_logs.mailroom')
            ->orderBy('id')
            ->limit(2000)
            ->get();

        return view('reports.transactions.mail-room', [
            'rows'  => $rows,
            'year'  => $year,
            'month' => $month,
        ]);
    }

    public function refunds(Request $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $rows = RawRow::forPeriod($year, $month)
            ->ofKind('papercut.transactions')
            ->get()
            ->filter(fn ($r) => str_starts_with(
                strtoupper($r->row_data['transaction type'] ?? ''),
                'REFUND'
            ));

        return view('reports.transactions.refunds', [
            'rows'  => $rows,
            'year'  => $year,
            'month' => $month,
        ]);
    }

    public function selfServe(Request $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $rows = EffectiveLineItem::forPeriod($year, $month)
            ->where('line_key', 'like', 'revenue_%')
            ->where('line_key', '<>', 'revenue_papercut')
            ->get();

        return view('reports.transactions.self-serve', [
            'rows'  => $rows,
            'year'  => $year,
            'month' => $month,
        ]);
    }

    public function overrides(Request $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $derived = LineItem::forPeriod($year, $month)->get()->keyBy('line_key');
        $overrides = Override::forPeriod($year, $month)->get()->keyBy('line_key');

        // Union of keys so admin sees every line — derived-only, override-only, and both.
        $keys = $derived->keys()->merge($overrides->keys())->unique()->sort();

        return view('reports.transactions.overrides', [
            'year'      => $year,
            'month'     => $month,
            'keys'      => $keys,
            'derived'   => $derived,
            'overrides' => $overrides,
        ]);
    }

    public function storeOverride(Request $request)
    {
        $data = $request->validate([
            'period_year'  => 'required|integer|min:2020|max:2100',
            'period_month' => 'required|integer|min:1|max:12',
            'line_key'     => 'required|string|max:64',
            'amount'       => 'required|numeric',
            'note'         => 'nullable|string|max:500',
        ]);
        $data['set_by'] = auth()->user()->username ?? 'unknown';
        $data['set_at'] = now();
        Override::updateOrCreate(
            [
                'period_year'  => $data['period_year'],
                'period_month' => $data['period_month'],
                'line_key'     => $data['line_key'],
            ],
            $data,
        );
        return redirect()->route('reports.transactions.overrides', [
            'year' => $data['period_year'],
            'month' => $data['period_month'],
        ])->with('success', 'Override saved.');
    }

    public function deleteOverride(Request $request, int $id)
    {
        Override::findOrFail($id)->delete();
        return back()->with('success', 'Override removed.');
    }

    public function lineItems(Request $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $rows = EffectiveLineItem::forPeriod($year, $month)
            ->orderBy('line_key')
            ->get();

        return view('reports.transactions.line-items', [
            'rows'  => $rows,
            'year'  => $year,
            'month' => $month,
        ]);
    }

    private function parseYm(string $ym): array
    {
        if (! preg_match('/^(\d{4})-(\d{1,2})$/', $ym, $m)) {
            abort(404);
        }
        return [(int) $m[1], (int) $m[2]];
    }
}
