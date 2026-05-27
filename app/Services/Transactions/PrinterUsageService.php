<?php

namespace App\Services\Transactions;

use App\Models\Asset;
use App\Models\Transactions\RawRow;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-printer rollups built from the TouchNet/PaperCut ingest in
 * `transaction_raw_rows`. Reads only `source_kind IN ('papercut.print_logs',
 * 'papercut.print_logs.mailroom')` rows that the upstream Azure Function
 * (or the §2.2 backfill migration) has tagged with `printer_asset_id`.
 *
 * Used by the per-asset Printing usage tab and the fleet
 * /reports/printing rollup. All values are derived; nothing persisted.
 */
class PrinterUsageService
{
    /** Source kinds that carry a printer serial in `row_data`. */
    public const PRINT_LOG_KINDS = [
        'papercut.print_logs',
        'papercut.print_logs.mailroom',
    ];

    /** Fieldsets considered "printers" for tab visibility. */
    public const PRINTER_FIELDSET_NAMES = [
        'Printers',
        'Printers & Scanners',
    ];

    public function summary(Asset $asset): array
    {
        $assetId = (int) $asset->id;

        $monthly = $this->monthlyVolume($assetId, 12);
        $latest = $this->latestMonth($assetId);
        $last30 = $this->last30DaysTotals($assetId);

        return [
            'asset'        => $asset,
            'last30'       => $last30,
            'monthly'      => $monthly,
            'latestPeriod' => $latest,
            'topUsers'     => $latest
                ? $this->topUsers($assetId, $latest['year'], $latest['month'])
                : collect(),
            'recentJobs'   => $this->recentJobs($assetId, 20),
            'glAllocation' => $latest
                ? $this->glAllocation($assetId, $latest['year'], $latest['month'])
                : collect(),
        ];
    }

    /**
     * Last-30-days totals: jobs · pages · cost · refund rate.
     * Filters by `ingested_at` rather than period because we want a sliding
     * window, not a calendar month.
     */
    public function last30DaysTotals(int $assetId): array
    {
        $rows = RawRow::forPrinter($assetId)
            ->whereIn('source_kind', self::PRINT_LOG_KINDS)
            ->where('ingested_at', '>=', Carbon::now()->subDays(30))
            ->get(['row_data']);

        return $this->aggregateJobs($rows);
    }

    /**
     * Trailing-N-months volume series. Returns rows ordered oldest-first so
     * the chart x-axis flows left → right.
     */
    public function monthlyVolume(int $assetId, int $months = 12): array
    {
        $cutoff = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $rows = RawRow::forPrinter($assetId)
            ->whereIn('source_kind', self::PRINT_LOG_KINDS)
            ->where(function ($q) use ($cutoff) {
                $q->where('period_year', '>', $cutoff->year)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->where('period_year', $cutoff->year)
                            ->where('period_month', '>=', $cutoff->month);
                    });
            })
            ->get(['period_year', 'period_month', 'row_data']);

        $grouped = $rows->groupBy(fn ($r) => sprintf('%04d-%02d', $r->period_year, $r->period_month));

        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = Carbon::now()->startOfMonth()->subMonths($i);
            $key = $month->format('Y-m');
            $bucket = $grouped->get($key, collect());
            $agg = $this->aggregateJobs($bucket);

            $series[] = [
                'label'  => $month->format('M Y'),
                'jobs'   => $agg['jobs'],
                'pages'  => $agg['pages'],
                'cost'   => $agg['cost'],
            ];
        }

        return $series;
    }

    /** Most recent period (year+month) we have any data for. */
    public function latestMonth(int $assetId): ?array
    {
        $row = RawRow::forPrinter($assetId)
            ->whereIn('source_kind', self::PRINT_LOG_KINDS)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first(['period_year', 'period_month']);

        return $row
            ? ['year' => (int) $row->period_year, 'month' => (int) $row->period_month]
            : null;
    }

    /**
     * Top users for a given period, ranked by job count.
     * "User" comes from row_data — the CSV exposes both `full name` and
     * `username`; we prefer the former and fall back to the latter.
     */
    public function topUsers(int $assetId, int $year, int $month, int $limit = 10): Collection
    {
        $rows = RawRow::forPrinter($assetId)
            ->forPeriod($year, $month)
            ->whereIn('source_kind', self::PRINT_LOG_KINDS)
            ->get(['row_data']);

        return $rows
            ->groupBy(fn ($r) => $this->userKey($r->row_data ?? []))
            ->map(function ($group, $user) {
                $agg = $this->aggregateJobs($group);
                return [
                    'user'  => $user,
                    'jobs'  => $agg['jobs'],
                    'pages' => $agg['pages'],
                ];
            })
            ->sortByDesc('jobs')
            ->take($limit)
            ->values();
    }

    /** Most recent jobs across all source kinds, newest first. */
    public function recentJobs(int $assetId, int $limit = 20): Collection
    {
        return RawRow::forPrinter($assetId)
            ->whereIn('source_kind', self::PRINT_LOG_KINDS)
            ->orderByDesc('ingested_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['ingested_at', 'source_kind', 'row_data'])
            ->map(fn ($r) => [
                'when'      => $r->ingested_at,
                'user'      => $this->userKey($r->row_data ?? []),
                'document'  => $r->row_data['document'] ?? $r->row_data['document name'] ?? '—',
                'pages'     => (int) ($r->row_data['total printed pages'] ?? $r->row_data['pages'] ?? 0),
                'cost'      => (float) ($r->row_data['cost'] ?? $r->row_data['amount'] ?? 0),
                'isRefund'  => $this->looksLikeRefund($r->row_data ?? []),
                'mailroom'  => $r->source_kind === 'papercut.print_logs.mailroom',
            ]);
    }

    /**
     * GL allocation for the period — sum of `cost` grouped by the GL on each
     * row. PaperCut writes the GL into `mapped_gl` or `gl_code` depending on
     * the export; tolerate both.
     */
    public function glAllocation(int $assetId, int $year, int $month): Collection
    {
        $rows = RawRow::forPrinter($assetId)
            ->forPeriod($year, $month)
            ->whereIn('source_kind', self::PRINT_LOG_KINDS)
            ->get(['row_data']);

        return $rows
            ->groupBy(fn ($r) => $this->glKey($r->row_data ?? []))
            ->map(function ($group, $gl) {
                $cost = 0.0;
                foreach ($group as $r) {
                    $cost += (float) ($r->row_data['cost'] ?? $r->row_data['amount'] ?? 0);
                }
                return ['gl' => $gl, 'cost' => $cost];
            })
            ->sortByDesc('cost')
            ->values();
    }

    /**
     * Fleet-level "this month" snapshot, one row per printer-asset.
     * Used by /reports/printing. On MySQL this is a single grouped query
     * with JSON_EXTRACT; on other drivers (SQLite/Postgres test env) it
     * falls back to in-memory aggregation since the test fixtures are
     * small and we only need cross-driver behavioural parity.
     */
    public function fleetMonth(int $year, int $month): Collection
    {
        $base = RawRow::query()
            ->whereNotNull('printer_asset_id')
            ->whereIn('source_kind', self::PRINT_LOG_KINDS)
            ->where('period_year', $year)
            ->where('period_month', $month);

        if (DB::connection()->getDriverName() === 'mysql') {
            return $base
                ->select([
                    'printer_asset_id',
                    DB::raw('COUNT(*)                            AS jobs'),
                    DB::raw("COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.\"total printed pages\"')) AS UNSIGNED)), 0) AS pages"),
                    DB::raw("COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.cost')) AS DECIMAL(12,4))), 0) AS cost"),
                    DB::raw("SUM(CASE WHEN JSON_EXTRACT(row_data, '$.refunded') = true OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.refunded'))) IN ('1','yes','true') THEN 1 ELSE 0 END) AS refunds"),
                    DB::raw('MAX(ingested_at)                    AS last_seen'),
                ])
                ->groupBy('printer_asset_id')
                ->get()
                ->keyBy('printer_asset_id');
        }

        return $base
            ->get(['printer_asset_id', 'row_data', 'ingested_at'])
            ->groupBy('printer_asset_id')
            ->map(function ($rows, $printerId) {
                $agg = $this->aggregateJobs($rows);
                $pages = 0;
                foreach ($rows as $r) {
                    $pages += (int) (($r->row_data['total printed pages'] ?? $r->row_data['pages'] ?? 0));
                }
                return (object) [
                    'printer_asset_id' => $printerId,
                    'jobs'             => $agg['jobs'],
                    'pages'            => $pages,
                    'cost'             => $agg['cost'],
                    'refunds'          => $agg['refunds'],
                    'last_seen'        => $rows->max('ingested_at'),
                ];
            });
    }

    /** Does an asset's model use a fieldset configured for printers? */
    public static function assetIsPrinter(?Asset $asset): bool
    {
        $fieldsetName = $asset?->model?->fieldset?->name;

        return $fieldsetName !== null
            && in_array($fieldsetName, self::PRINTER_FIELDSET_NAMES, true);
    }

    private function aggregateJobs(iterable $rows): array
    {
        $jobs = 0;
        $pages = 0;
        $cost = 0.0;
        $refunds = 0;

        foreach ($rows as $r) {
            $data = $r->row_data ?? [];
            $jobs++;
            $pages += (int) ($data['total printed pages'] ?? $data['pages'] ?? 0);
            $cost += (float) ($data['cost'] ?? $data['amount'] ?? 0);
            if ($this->looksLikeRefund($data)) {
                $refunds++;
            }
        }

        return [
            'jobs'       => $jobs,
            'pages'      => $pages,
            'cost'       => round($cost, 2),
            'refunds'    => $refunds,
            'refundRate' => $jobs > 0 ? round($refunds / $jobs, 4) : 0.0,
        ];
    }

    private function userKey(array $data): string
    {
        $name = trim((string) ($data['full name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $user = trim((string) ($data['username'] ?? $data['user'] ?? ''));
        return $user !== '' ? $user : '—';
    }

    private function glKey(array $data): string
    {
        return (string) (
            $data['mapped_gl']
            ?? $data['gl_code']
            ?? $data['gl']
            ?? '—'
        );
    }

    private function looksLikeRefund(array $data): bool
    {
        $flag = $data['refunded'] ?? null;
        if ($flag === true || $flag === 1 || $flag === '1') {
            return true;
        }
        if (is_string($flag) && in_array(strtolower($flag), ['yes', 'true', 'y'], true)) {
            return true;
        }
        $type = strtoupper((string) ($data['transaction type'] ?? ''));
        return str_contains($type, 'REFUND');
    }
}
