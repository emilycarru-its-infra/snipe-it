<?php

namespace App\Services;

use App\Models\PurchaseOrder;

/**
 * Live carry-forward: the prior fiscal year's unused PO budget, computed
 * on demand instead of posted as a ledger snapshot — so it tracks the
 * committed data as it's corrected, with nothing to delete and re-post.
 *
 * Unused = the sum over the prior FY's purchase orders of (PO budget −
 * committed against that PO), with committed read from the asset source
 * of truth (App\Services\AssetCommitted). The POs are the budget
 * envelopes: spend filed against a PO with no budget record doesn't
 * drain another PO's envelope, and an overspent PO nets against the
 * others.
 *
 * Committed is deliberately NOT scoped to the source fiscal year: a
 * blanket PO from the prior FY can carry this year's purchases too
 * (e.g. schedules 007/008 — FY2026-27 spend on a FY2025-26 PO), and
 * that spend drains the envelope all the same. Scoping by purchase
 * date would leave it out and overstate the carry.
 */
class BudgetCarry
{
    /**
     * The live carry into $targetFy (canonical `FY2026-27` shape), or
     * null when the prior FY has no PO budgets or nothing left unused.
     * Returns source_fy, po_budgets, committed and unused.
     */
    public static function intoFy(string $targetFy): ?array
    {
        $sourceFy = self::previousFiscalYear($targetFy);
        if (! $sourceFy) {
            return null;
        }

        $purchaseOrders = PurchaseOrder::where('fiscal_year', $sourceFy)->get();
        $poBudgets = (float) $purchaseOrders->sum(fn ($po) => (float) $po->budget);
        if ($poBudgets <= 0) {
            return null;
        }

        $committedByPo = AssetCommitted::byPo();
        $committed = (float) $purchaseOrders->sum(
            fn ($po) => (float) ($committedByPo[$po->po_number] ?? 0.0)
        );

        $unused = round($poBudgets - $committed, 2);
        if ($unused <= 0) {
            return null;
        }

        return [
            'source_fy' => $sourceFy,
            'po_budgets' => $poBudgets,
            'committed' => $committed,
            'unused' => $unused,
        ];
    }

    /**
     * The fiscal year before a canonical `FY2025-26` label, or null.
     */
    private static function previousFiscalYear(string $fy): ?string
    {
        if (! preg_match('/^FY(\d{4})-(\d{2})$/', $fy, $m)) {
            return null;
        }

        $start = (int) $m[1] - 1;

        return 'FY'.$start.'-'.substr((string) ($start + 1), -2);
    }
}
