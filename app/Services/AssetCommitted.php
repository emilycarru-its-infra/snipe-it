<?php

namespace App\Services;

use App\Models\Asset;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
 *
 * Not every dollar that drains a PO buys a device: lease buyouts and
 * terminations commit money with no new asset row, and credits hand money
 * back. Those live as order_invoices typed buyout / termination / credit,
 * and are folded into the same per-PO map — signs forced so adjustments
 * always add and credits always subtract, however the subtotal was keyed.
 */
class AssetCommitted
{
    /**
     * Committed totals keyed by PO number, optionally scoped to one
     * ECU fiscal year (`FY2025-26`, April–March).
     */
    public static function byPo(?string $fy = null): array
    {
        $poColumn = 'po_number';
        $warrantyColumn = 'warranty_soft_cost';

        // Only assets that carry a university PO (P00…) on their native PO
        // Number column count toward committed; CSI-schedule values (301452-…)
        // and blanks don't map to a purchase order.
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
            $warranty = self::parseMoney($asset->{$warrantyColumn});
            $map[$po] = ($map[$po] ?? 0.0) + (float) $asset->purchase_cost + $warranty;
        }

        foreach (self::invoiceAdjustmentsByPo($fy) as $po => $amount) {
            $map[$po] = round(($map[$po] ?? 0.0) + $amount, 2);
        }

        return $map;
    }

    /**
     * Net buyout / termination / credit invoice amounts keyed by PO number,
     * FY-scoped by invoice_date to mirror the purchase_date scoping of the
     * asset side.
     */
    public static function invoiceAdjustmentsByPo(?string $fy = null): array
    {
        $query = DB::table('order_invoices')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'order_invoices.purchase_order_id')
            // A dateless adjustment would count in unscoped totals (budget
            // carry-forward) while appearing in no fiscal-year view — fail
            // closed instead: it counts nowhere until it carries a date.
            ->whereNotNull('order_invoices.invoice_date')
            ->whereIn('order_invoices.invoice_type', ['buyout', 'termination', 'credit']);

        if ($range = self::fiscalYearRange($fy)) {
            $query->whereBetween('order_invoices.invoice_date', $range);
        }

        $map = [];
        foreach ($query->get(['purchase_orders.po_number', 'order_invoices.invoice_type', 'order_invoices.subtotal']) as $row) {
            $amount = abs((float) $row->subtotal);
            if ($row->invoice_type === 'credit') {
                $amount = -$amount;
            }
            $po = trim((string) $row->po_number);
            $map[$po] = ($map[$po] ?? 0.0) + $amount;
        }

        return $map;
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
