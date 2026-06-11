<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CustomField;
use Carbon\Carbon;

/**
 * Committed spend computed from the ASSET source of truth: each device's
 * purchase_cost (equipment) plus its Warranty/Soft Cost field, grouped by
 * the university PO carried on the asset's "PO Number" field, scoped to a
 * fiscal year by purchase_date.
 *
 * This is what makes committed reconcile to the real, received fleet
 * instead of the drifted order-item import — outstanding (not-yet-shipped)
 * orders have no asset, so they fall to the Orders model rather than
 * inflating committed. Shared by the procurement dashboard and the budget
 * carry-forward so both read the same number.
 */
class AssetCommitted
{
    /**
     * Committed totals keyed by PO number, optionally scoped to one
     * ECU fiscal year (`FY2025-26`, April–March).
     */
    public static function byPo(?string $fy = null): array
    {
        $poColumn = self::fieldColumn('PO Number');
        $warrantyColumn = self::fieldColumn('Warranty/Soft Cost');

        if (! $poColumn) {
            return [];
        }

        // Only assets that carry a university PO (P00…) on their PO Number
        // field count toward committed; CSI-schedule values (301452-…) and
        // blanks don't map to a purchase order.
        $query = Asset::query()->where($poColumn, 'like', 'P00%');

        if ($range = self::fiscalYearRange($fy)) {
            $query->whereBetween('purchase_date', $range);
        }

        $map = [];
        foreach ($query->get() as $asset) {
            $po = trim((string) $asset->{$poColumn});
            if ($po === '') {
                continue;
            }
            $warranty = $warrantyColumn ? self::parseMoney($asset->{$warrantyColumn}) : 0.0;
            $map[$po] = ($map[$po] ?? 0.0) + (float) $asset->purchase_cost + $warranty;
        }

        return $map;
    }

    /**
     * Total committed for one fiscal year across every PO.
     */
    public static function totalForFy(?string $fy = null): float
    {
        return (float) array_sum(self::byPo($fy));
    }

    private static function fieldColumn(string $name): ?string
    {
        return CustomField::where('name', $name)->first()?->db_column;
    }

    /**
     * The [start, end] bounds of an ECU fiscal year (April 1 → March 31).
     * Accepts `FY2025-26`, `2025-26` and `FY25-26`; null/'all' yields null
     * (no scoping).
     */
    private static function fiscalYearRange(?string $fy): ?array
    {
        if ($fy === null || trim($fy) === '' || strtolower(trim($fy)) === 'all') {
            return null;
        }

        if (preg_match('/(\d{4})\s*-\s*\d{2}$/', trim($fy), $m)) {
            $start = (int) $m[1];
        } elseif (preg_match('/(\d{2})\s*-\s*\d{2}$/', trim($fy), $m)) {
            $start = 2000 + (int) $m[1];
        } else {
            return null;
        }

        return [
            Carbon::create($start, 4, 1)->startOfDay(),
            Carbon::create($start + 1, 3, 31)->endOfDay(),
        ];
    }

    private static function parseMoney($value): float
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
}
