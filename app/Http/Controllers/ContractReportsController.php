<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractSerial;
use Illuminate\Http\Request;
use League\Csv\EscapeFormula;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The contract drill-down reports. Each one is a section on /contracts —
 * fetched inline in `embed` mode — and a standalone page at
 * /contracts/<report> so it can be linked, printed or pulled as CSV on its
 * own. The shape mirrors ProcurementReportsController so finance/admin users
 * get a consistent "view inline → open → download" workflow regardless of
 * whether they're looking at purchasing or contracts.
 *
 * The dashboard half (tiles + charts) lives in ContractsController.
 *
 * Authorization is the plain contracts permission: these are sections of
 * /contracts, so anyone who can open that page can open the section it
 * embeds. They answered to a separate `reports.contracts.view` while they
 * lived under /reports.
 */
class ContractReportsController extends Controller
{
    // ─── Sub-reports ────────────────────────────────────────────────────

    public function expiringSoon(Request $request)
    {
        $this->authorize('view', Contract::class);

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
            'contracts.reports.expiring-soon',
            $this->buildExpiringReport($rows),
            extraParams: ['days' => $days]
        );
    }

    /**
     * The synthesized grouping rows: one per (area, product) pair that has
     * more than one fiscal-year contract, with its per-year children rolled
     * up. Written by the tdx-to-snipe-contracts function, not by hand.
     */
    public function renewalSeries(Request $request)
    {
        $this->authorize('view', Contract::class);

        $series = Contract::where('is_synthesized', true)
            ->with(['children' => fn ($q) => $q->orderBy('fiscal_year')])
            ->orderBy('theme')->orderBy('product')
            ->get();

        return $this->render(
            $request,
            'contracts-renewal-series',
            trans('admin/contracts/general.report_renewal_series'),
            'contracts.reports.renewal-series',
            $this->buildRenewalSeriesReport($series)
        );
    }

    public function byArea(Request $request)
    {
        $this->authorize('view', Contract::class);

        $rows = Contract::realOnly()
            ->selectRaw('COALESCE(theme, "—") AS theme, COUNT(*) AS n, SUM(total_cost) AS total')
            ->groupBy('theme')
            ->orderByDesc('total')
            ->get();

        return $this->render(
            $request,
            'contracts-by-area',
            trans('admin/contracts/general.report_by_area'),
            'contracts.reports.by-area',
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
        $this->authorize('view', Contract::class);

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
            'contracts.reports.by-provider',
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
        $this->authorize('view', Contract::class);

        $serials = ContractSerial::with('contract', 'asset')
            ->orderBy('serial')
            ->get();

        return $this->render(
            $request,
            'contracts-serial-register',
            trans('admin/contracts/general.report_serial_register'),
            'contracts.reports.serial-register',
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
        $this->authorize('view', Contract::class);

        $rows = $this->namingViolators()->get();

        return $this->render(
            $request,
            'contracts-naming-violators',
            trans('admin/contracts/general.report_naming_violators'),
            'contracts.reports.naming-violators',
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
        $this->authorize('view', Contract::class);

        $rows = $this->stale()->orderBy('tdx_modified_date')->get();

        return $this->render(
            $request,
            'contracts-stale-in-tdx',
            trans('admin/contracts/general.report_stale'),
            'contracts.reports.stale',
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

    private function buildRenewalSeriesReport($series): array
    {
        $records = [];
        foreach ($series as $u) {
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
                trans('admin/contracts/general.series_contracts'),
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

        // Embed mode returns the bare table for the section that /contracts
        // lazy-loads inline; the full page keeps the layout and the header.
        if ($request->boolean('embed')) {
            return view('contracts/reports/_report-table', [
                'columns' => $report['columns'],
                'rows'    => $report['records'],
                'footer'  => $report['footer'] ?? null,
            ]);
        }

        return view('contracts/reports/show', [
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
