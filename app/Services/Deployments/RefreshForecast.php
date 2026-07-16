<?php

namespace App\Services\Deployments;

use App\Models\Asset;
use App\Models\DeploymentItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-collection of devices due for refresh in a fiscal year — the
 * headline E1 feature behind /reports/deployments/forecast. Replaces Rod
 * manually flipping devices to a stopgap "Active (Lease End)" status: it
 * sweeps assets whose native EOL date OR (if present) "Lease End Date"
 * custom field lands inside an ECU fiscal year (April 1 -> March 31), and
 * lets a tech bulk-add them to a deployment wave as replacement items.
 *
 * NOTE: the FY helpers below mirror ProcurementReportsController's private
 * normalizeFy / fiscalYearStartYear / fiscalYearRange / fiscalYearFromEndDate;
 * kept self-contained here — candidate for a shared util later.
 */
class RefreshForecast
{
    /**
     * Canonicalize a fiscal-year string to `FY2025-26`, or null for an
     * empty / "all" / unparseable input.
     */
    public static function normalizeFy(?string $fy): ?string
    {
        if ($fy === null) {
            return null;
        }

        $fy = trim($fy);
        if ($fy === '' || strtolower($fy) === 'all') {
            return null;
        }

        if (preg_match('/(\d{4})\s*-\s*(\d{2})$/', $fy, $m)) {
            return 'FY'.$m[1].'-'.$m[2];
        }

        if (preg_match('/(\d{2})\s*-\s*(\d{2})$/', $fy, $m)) {
            return 'FY20'.$m[1].'-'.$m[2];
        }

        if (preg_match('/(\d{4})$/', $fy, $m)) {
            $start = (int) $m[1];

            return 'FY'.$start.'-'.substr((string) ($start + 1), -2);
        }

        return null;
    }

    /** The start calendar year of a canonical FY label (FY2025-26 -> 2025), or null. */
    public static function fiscalYearStartYear(?string $fy): ?int
    {
        $fy = self::normalizeFy($fy);
        if ($fy === null) {
            return null;
        }

        return (int) substr($fy, 2, 4);
    }

    /**
     * The [start, end] Carbon bounds of a fiscal year (April 1 -> March 31),
     * or null for an unparseable / "all" FY.
     */
    public static function fiscalYearRange(?string $fy): ?array
    {
        $startYear = self::fiscalYearStartYear($fy);
        if ($startYear === null) {
            return null;
        }

        return [
            \Carbon\Carbon::create($startYear, 4, 1)->startOfDay(),
            \Carbon\Carbon::create($startYear + 1, 3, 31)->endOfDay(),
        ];
    }

    /** The FY label a 'Y-m-d' (or m/d/Y, Y/m/d, d/m/Y) date string falls into, or null. */
    public static function fiscalYearFromEndDate(?string $endDateStr): ?string
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
     * The native `lease_end_date` column (mirrored from the "Lease End Date"
     * custom field), or null in environments where it hasn't been migrated in
     * yet. Every consumer MUST guard against null before touching the column.
     */
    public static function leaseEndColumn(): ?string
    {
        return Schema::hasColumn('assets', 'lease_end_date') ? 'lease_end_date' : null;
    }

    /**
     * Distinct FY labels we have refresh candidates for, derived from
     * assets.asset_eol_date and (if present) the Lease End Date custom
     * field. Sorted descending, always including the current + next FY so
     * an empty board is still usable.
     */
    public function availableFiscalYears(): array
    {
        $labels = [];

        $eolDates = Asset::query()
            ->whereNotNull('asset_eol_date')
            ->pluck('asset_eol_date');
        foreach ($eolDates as $d) {
            if ($fy = self::fiscalYearFromEndDate((string) $d)) {
                $labels[$fy] = true;
            }
        }

        $leaseCol = self::leaseEndColumn();
        if ($leaseCol !== null) {
            $leaseDates = Asset::query()
                ->whereNotNull($leaseCol)
                ->where($leaseCol, '!=', '')
                ->pluck($leaseCol);
            foreach ($leaseDates as $d) {
                if ($fy = self::fiscalYearFromEndDate((string) $d)) {
                    $labels[$fy] = true;
                }
            }
        }

        // Always offer current + next FY (ECU FY starts in April).
        $now = \Carbon\Carbon::now();
        $startYear = $now->month >= 4 ? $now->year : $now->year - 1;
        foreach ([$startYear, $startYear + 1] as $sy) {
            $labels[sprintf('FY%d-%02d', $sy, ($sy + 1) % 100)] = true;
        }

        $out = array_keys($labels);
        rsort($out);

        return $out;
    }

    /**
     * Assets to refresh in $fy: not archived, whose native EOL date OR (if
     * present) the Lease End Date custom field lands in the FY window, and
     * that aren't already on a deployment_item (as asset or replacement),
     * so they're never double-added. Each row is annotated with
     * refresh_reason ('eol'|'lease'|'both') and source_date (Y-m-d). Returns
     * an empty collection when $fy is null — a FY choice is required.
     */
    public function forFiscalYear(?string $fy): Collection
    {
        $range = self::fiscalYearRange($fy);
        if ($range === null) {
            return collect();
        }

        [$start, $end] = $range;
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();
        $leaseCol = self::leaseEndColumn();

        $query = Asset::query()
            ->NotArchived()
            ->with(['model', 'status', 'location'])
            ->where(function ($q) use ($start, $end, $leaseCol, $startStr, $endStr) {
                $q->whereBetween('asset_eol_date', [$start, $end]);
                if ($leaseCol !== null) {
                    // Native lease_end_date is a DATE; 'Y-m-d' bounds compare fine.
                    $q->orWhereBetween($leaseCol, [$startStr, $endStr]);
                }
            });

        // Exclude assets already tracked by a deployment item (either as
        // the incoming device or the device being replaced).
        $tracked = DeploymentItem::query()
            ->whereNotNull('asset_id')
            ->pluck('asset_id')
            ->merge(
                DeploymentItem::query()->whereNotNull('replaces_asset_id')->pluck('replaces_asset_id')
            )
            ->unique()
            ->all();

        if (! empty($tracked)) {
            $query->whereNotIn('assets.id', $tracked);
        }

        $assets = $query->orderBy('asset_eol_date')->orderBy('name')->get();

        return $assets->map(function (Asset $asset) use ($start, $end, $leaseCol, $startStr, $endStr) {
            $eolIn = false;
            if ($asset->asset_eol_date) {
                $eol = \Carbon\Carbon::parse($asset->asset_eol_date);
                $eolIn = $eol->betweenIncluded($start, $end);
            }

            $leaseIn = false;
            $leaseVal = ($leaseCol !== null) ? (string) ($asset->{$leaseCol} ?? '') : '';
            if ($leaseVal !== '') {
                $leaseIn = ($leaseVal >= $startStr && $leaseVal <= $endStr);
            }

            $reason = match (true) {
                $eolIn && $leaseIn => 'both',
                $leaseIn => 'lease',
                default => 'eol',
            };

            $sourceDate = match ($reason) {
                'lease' => $leaseVal,
                default => $asset->asset_eol_date ? \Carbon\Carbon::parse($asset->asset_eol_date)->toDateString() : $leaseVal,
            };

            // Transient annotations consumed by the forecast view + addFromForecast.
            $asset->refresh_reason = $reason;
            $asset->source_date = $sourceDate;

            return $asset;
        });
    }
}
