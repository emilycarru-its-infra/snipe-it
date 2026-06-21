<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Contract;
use App\Models\ContractSerial;
use Illuminate\Http\Request;
use League\Csv\EscapeFormula;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contracts dashboard + sub-reports. The shape mirrors
 * ProcurementReportsController so finance/admin users get a consistent
 * "summary cards → charts → sub-report table → CSV export" workflow
 * regardless of whether they're looking at purchasing or contracts.
 */
class ContractReportsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('reports.contracts.view');

        $allFiscalYears = Contract::whereNotNull('fiscal_year')
            ->distinct()->orderBy('fiscal_year')->pluck('fiscal_year');

        // Default to the current FY when no ?fiscal_year is passed so the
        // contracts dashboard opens on this year. When the current FY holds no
        // contracts yet (early in a fiscal year, or contracts dated by signing
        // year), fall back to the most recent FY that actually has contracts —
        // $allFiscalYears is ascending and FY labels sort chronologically — so
        // the dashboard never silently opens on the all-time view. An explicit
        // `?fiscal_year=all` is the opt-out for all-time.
        $rawFy = $request->query('fiscal_year');
        $current = Helper::currentFiscalYear();
        $defaultFy = $allFiscalYears->contains($current) ? $current : $allFiscalYears->last();
        if ($rawFy === 'all') {
            $selectedFy = null;
        } elseif ($rawFy !== null && $allFiscalYears->contains($rawFy)) {
            $selectedFy = $rawFy;
        } else {
            $selectedFy = $defaultFy;
        }

        $base = Contract::query()
            ->realOnly()
            ->when($selectedFy, fn ($q) => $q->where('fiscal_year', $selectedFy));

        $activeCount       = (clone $base)->where('is_active', true)->count();
        $totalCost         = (float) (clone $base)->sum('total_cost');
        $expiring30        = (clone $base)->expiringWithin(30)->count();
        $expiring90        = (clone $base)->expiringWithin(90)->count();
        $umbrellaCount     = Contract::where('is_synthesized', true)->count();
        $serialRegister    = ContractSerial::count();
        $namingViolators   = $this->namingViolators()->count();
        $staleCount        = $this->stale()->count();

        // Spend by fiscal year (bar). Use a single query grouped on fiscal_year.
        $spendByFy = Contract::realOnly()
            ->whereNotNull('fiscal_year')
            ->selectRaw('fiscal_year, SUM(total_cost) AS total')
            ->groupBy('fiscal_year')
            ->orderBy('fiscal_year')
            ->pluck('total', 'fiscal_year');

        // Count by theme (stacked bar / horizontal).
        $countByTheme = Contract::realOnly()
            ->whereNotNull('theme')
            ->when($selectedFy, fn ($q) => $q->where('fiscal_year', $selectedFy))
            ->selectRaw('theme, COUNT(*) AS n')
            ->groupBy('theme')
            ->orderByDesc('n')
            ->pluck('n', 'theme');

        // Top providers by spend (doughnut).
        $spendByProvider = Contract::realOnly()
            ->join('suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
            ->when($selectedFy, fn ($q) => $q->where('contracts.fiscal_year', $selectedFy))
            ->selectRaw('suppliers.name AS provider, SUM(contracts.total_cost) AS total')
            ->groupBy('suppliers.name')
            ->orderByDesc('total')
            ->limit(10)
            ->pluck('total', 'provider');

        // Renewal calendar — count of end_date per month, current + next FY window.
        $renewalCalendar = Contract::realOnly()
            ->where('is_active', true)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfMonth(), now()->addYear()->endOfMonth()])
            ->selectRaw("DATE_FORMAT(end_date, '%Y-%m') AS ym, COUNT(*) AS n")
            ->groupBy('ym')
            ->orderBy('ym')
            ->pluck('n', 'ym');

        return view('reports/contracts', [
            'allFiscalYears'   => $allFiscalYears,
            'selectedFy'       => $selectedFy,
            'activeCount'      => $activeCount,
            'totalCost'        => $totalCost,
            'expiring30'       => $expiring30,
            'expiring90'       => $expiring90,
            'umbrellaCount'    => $umbrellaCount,
            'serialRegister'   => $serialRegister,
            'namingViolators'  => $namingViolators,
            'staleCount'       => $staleCount,
            'spendByFy'        => $spendByFy,
            'countByTheme'     => $countByTheme,
            'spendByProvider'  => $spendByProvider,
            'renewalCalendar'  => $renewalCalendar,
        ]);
    }

    // ─── Sub-reports ────────────────────────────────────────────────────

    public function expiringSoon(Request $request)
    {
        $this->authorize('reports.contracts.view');

        $days = max(1, (int) $request->query('days', 90));
        $rows = Contract::realOnly()
            ->with('supplier', 'parent')
            ->where('is_active', true)
            ->expiringWithin($days)
            ->orderBy('end_date')
            ->get();

        return $this->render(
            $request,
            "contracts-expiring-{$days}d",
            trans('admin/contracts/general.report_expiring_soon', ['days' => $days]),
            'reports.contracts.expiring-soon',
            $this->buildExpiringReport($rows),
            extraParams: ['days' => $days]
        );
    }

    public function umbrellas(Request $request)
    {
        $this->authorize('reports.contracts.view');

        $umbrellas = Contract::where('is_synthesized', true)
            ->with(['children' => fn ($q) => $q->orderBy('fiscal_year')])
            ->orderBy('theme')->orderBy('product')
            ->get();

        return $this->render(
            $request,
            'contracts-umbrellas',
            trans('admin/contracts/general.report_umbrellas'),
            'reports.contracts.umbrellas',
            $this->buildUmbrellaReport($umbrellas)
        );
    }

    public function byTheme(Request $request)
    {
        $this->authorize('reports.contracts.view');

        $rows = Contract::realOnly()
            ->selectRaw('COALESCE(theme, "—") AS theme, COUNT(*) AS n, SUM(total_cost) AS total')
            ->groupBy('theme')
            ->orderByDesc('total')
            ->get();

        return $this->render(
            $request,
            'contracts-by-theme',
            trans('admin/contracts/general.report_by_theme'),
            'reports.contracts.by-theme',
            [
                'columns' => [trans('admin/contracts/general.theme'), trans('general.count'), trans('admin/contracts/general.total_cost')],
                'records' => $rows->map(fn ($r) => [
                    'cells' => [$r->theme, (int) $r->n, $this->money($r->total)],
                ])->all(),
            ]
        );
    }

    public function byProvider(Request $request)
    {
        $this->authorize('reports.contracts.view');

        $rows = Contract::realOnly()
            ->leftJoin('suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
            ->selectRaw('COALESCE(suppliers.name, "—") AS provider, COUNT(*) AS n, SUM(contracts.total_cost) AS total')
            ->groupBy('suppliers.name')
            ->orderByDesc('total')
            ->get();

        return $this->render(
            $request,
            'contracts-by-provider',
            trans('admin/contracts/general.report_by_provider'),
            'reports.contracts.by-provider',
            [
                'columns' => [trans('general.supplier'), trans('general.count'), trans('admin/contracts/general.total_cost')],
                'records' => $rows->map(fn ($r) => [
                    'cells' => [$r->provider, (int) $r->n, $this->money($r->total)],
                ])->all(),
            ]
        );
    }

    public function serialRegister(Request $request)
    {
        $this->authorize('reports.contracts.view');

        $serials = ContractSerial::with('contract', 'asset')
            ->orderBy('serial')
            ->get();

        return $this->render(
            $request,
            'contracts-serial-register',
            trans('admin/contracts/general.report_serial_register'),
            'reports.contracts.serial-register',
            [
                'columns' => [
                    trans('admin/contracts/general.serial'),
                    trans('admin/contracts/general.source'),
                    trans('admin/contracts/general.contract'),
                    trans('general.asset_tag'),
                ],
                'records' => $serials->map(fn ($s) => [
                    'cells' => [
                        $s->serial,
                        $s->source,
                        $s->contract?->name ?? '—',
                        $s->asset?->asset_tag ?? '—',
                    ],
                ])->all(),
            ]
        );
    }

    public function namingViolatorsReport(Request $request)
    {
        $this->authorize('reports.contracts.view');

        $rows = $this->namingViolators()->get();

        return $this->render(
            $request,
            'contracts-naming-violators',
            trans('admin/contracts/general.report_naming_violators'),
            'reports.contracts.naming-violators',
            [
                'columns' => [
                    trans('admin/contracts/general.tdx_id'),
                    trans('admin/contracts/general.contract_number'),
                    trans('admin/contracts/general.name'),
                ],
                'records' => $rows->map(fn ($c) => [
                    'cells' => [$c->tdx_id, $c->contract_number, $c->name],
                ])->all(),
            ]
        );
    }

    public function staleReport(Request $request)
    {
        $this->authorize('reports.contracts.view');

        $rows = $this->stale()->orderBy('tdx_modified_date')->get();

        return $this->render(
            $request,
            'contracts-stale-in-tdx',
            trans('admin/contracts/general.report_stale'),
            'reports.contracts.stale',
            [
                'columns' => [
                    trans('admin/contracts/general.tdx_id'),
                    trans('admin/contracts/general.name'),
                    trans('admin/contracts/general.fiscal_year'),
                    trans('admin/contracts/general.tdx_modified_date'),
                ],
                'records' => $rows->map(fn ($c) => [
                    'cells' => [
                        $c->tdx_id,
                        $c->name,
                        $c->fiscal_year ?? '—',
                        optional($c->tdx_modified_date)->toDateString() ?? '—',
                    ],
                ])->all(),
            ]
        );
    }

    // ─── Query helpers ──────────────────────────────────────────────────

    private function namingViolators()
    {
        return Contract::query()
            ->whereNotNull('tdx_id')
            ->where(function ($q) {
                $q->whereNull('theme')->orWhereNull('fiscal_year');
            });
    }

    private function stale()
    {
        return Contract::realOnly()
            ->where('is_active', true)
            ->whereNotNull('tdx_modified_date')
            ->where('tdx_modified_date', '<', now()->subDays(180));
    }

    // ─── Report builders ────────────────────────────────────────────────

    private function buildExpiringReport($rows): array
    {
        return [
            'columns' => [
                trans('admin/contracts/general.end_date'),
                trans('admin/contracts/general.name'),
                trans('admin/contracts/general.fiscal_year'),
                trans('general.supplier'),
                trans('admin/contracts/general.total_cost'),
            ],
            'records' => $rows->map(fn ($c) => [
                'cells' => [
                    optional($c->end_date)->toDateString() ?? '—',
                    $c->name,
                    $c->fiscal_year ?? '—',
                    $c->supplier?->name ?? '—',
                    $this->money($c->total_cost),
                ],
            ])->all(),
        ];
    }

    private function buildUmbrellaReport($umbrellas): array
    {
        $records = [];
        foreach ($umbrellas as $u) {
            $records[] = [
                'cells' => [
                    $u->theme,
                    $u->product,
                    $u->children->count(),
                    $this->money((float) $u->children->sum('total_cost')),
                    $u->children->pluck('fiscal_year')->filter()->implode(', '),
                ],
            ];
        }

        return [
            'columns' => [
                trans('admin/contracts/general.theme'),
                trans('admin/contracts/general.product'),
                trans('admin/contracts/general.children'),
                trans('admin/contracts/general.total_cost'),
                trans('admin/contracts/general.fiscal_year'),
            ],
            'records' => $records,
        ];
    }

    // ─── Render / CSV helpers (mirrors ProcurementReportsController) ────

    private function render(Request $request, string $filename, string $title, string $routeName, array $report, string $controls = '', array $extraParams = [])
    {
        if ($request->query('format') === 'csv') {
            return $this->streamReportCsv($filename, $report);
        }

        return view('reports/contracts/show', [
            'reportTitle' => $title,
            'columns'     => $report['columns'],
            'rows'        => $report['records'],
            'footer'      => $report['footer'] ?? null,
            'controls'    => $controls,
            'downloadUrl' => route($routeName, array_merge(['format' => 'csv'], $extraParams)),
        ]);
    }

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
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'-'.date('Y-m-d').'.csv"',
        ]);
    }

    private function money($value): string
    {
        if ($value === null) {
            return '';
        }
        $value = (float) $value;
        $formatted = '$'.number_format(abs($value), 2);
        return $value < 0 ? '('.$formatted.')' : $formatted;
    }
}
