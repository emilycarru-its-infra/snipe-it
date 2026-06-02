<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\BudgetAllocation;
use App\Models\PurchaseOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Write endpoints for the BudgetAllocation ledger.
 *
 * Allocations are append-only. There's no `update`; if a user needs to
 * correct a mistake they post a new row with source=adjustment and a
 * negative amount referring back to the offending row in the description.
 * This keeps the budget history auditable.
 */
class BudgetAllocationsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('budget_allocations.manage');

        $data = $request->validate([
            'fiscal_year'    => 'required|string|max:16',
            'area'           => 'nullable|string|max:191',
            'amount'         => 'required|numeric',
            'source'         => 'required|in:'.implode(',', BudgetAllocation::SOURCES),
            'description'    => 'nullable|string|max:2000',
            'effective_date' => 'nullable|date',
        ]);

        $data['created_by'] = Auth::id();

        BudgetAllocation::create($data);

        return redirect()
            ->route('reports.procurement', ['fiscal_year' => $data['fiscal_year']])
            ->with('success', trans('admin/budget-allocations/general.allocation_added'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->authorize('budget_allocations.manage');

        $row = BudgetAllocation::findOrFail($id);
        $fy  = $row->fiscal_year;
        $row->delete();

        return redirect()
            ->route('reports.procurement', ['fiscal_year' => $fy])
            ->with('success', trans('admin/budget-allocations/general.allocation_removed'));
    }

    /**
     * Roll a fiscal year's unspent budget into the next one.
     *
     * Unspent = approved budget for the prior FY (its allocation ledger)
     * minus what was committed against it (line items booked on orders in
     * that FY — the same order-FY attribution the reports use). Posting it
     * as a carry_forward allocation in the target FY is how "PO budget that
     * was approved but never ordered" becomes available again next year.
     *
     * One carry-forward per target FY: to recompute, delete the existing
     * row and run it again — keeps the append-only ledger honest.
     */
    public function carryForward(Request $request): RedirectResponse
    {
        $this->authorize('budget_allocations.manage');

        $data = $request->validate([
            'target_fiscal_year' => 'required|string|max:16',
        ]);

        $targetFy = $this->canonicalFy($data['target_fiscal_year']);
        $sourceFy = $targetFy ? $this->previousFiscalYear($targetFy) : null;

        if (! $targetFy || ! $sourceFy) {
            return back()->with('error', trans('admin/budget-allocations/general.carry_forward_none', [
                'source' => $data['target_fiscal_year'],
            ]));
        }

        $redirect = redirect()->route('reports.procurement', ['fiscal_year' => $targetFy]);

        if (BudgetAllocation::where('fiscal_year', $targetFy)->where('source', 'carry_forward')->exists()) {
            return $redirect->with('error', trans('admin/budget-allocations/general.carry_forward_exists', [
                'source' => $sourceFy,
                'target' => $targetFy,
            ]));
        }

        $approved = (float) BudgetAllocation::where('fiscal_year', $sourceFy)->sum('amount');
        if ($approved <= 0) {
            return $redirect->with('error', trans('admin/budget-allocations/general.carry_forward_no_budget', [
                'source' => $sourceFy,
            ]));
        }

        // Committed against the prior FY, attributed by the booking order's
        // FY so a blanket PO only contributes the slice it spent that year.
        $committed = (float) PurchaseOrder::all()->sum(fn ($po) => $po->committedTotalForFy($sourceFy));
        $unspent = round($approved - $committed, 2);

        if ($unspent <= 0) {
            return $redirect->with('error', trans('admin/budget-allocations/general.carry_forward_none', [
                'source' => $sourceFy,
            ]));
        }

        BudgetAllocation::create([
            'fiscal_year'    => $targetFy,
            'amount'         => $unspent,
            'source'         => 'carry_forward',
            'description'    => trans('admin/budget-allocations/general.carry_forward_desc', [
                'source'    => $sourceFy,
                'approved'  => '$'.Helper::formatCurrencyOutput($approved),
                'committed' => '$'.Helper::formatCurrencyOutput($committed),
            ]),
            'effective_date' => now()->toDateString(),
            'created_by'     => Auth::id(),
        ]);

        return $redirect->with('success', trans('admin/budget-allocations/general.carry_forward_done', [
            'amount' => '$'.Helper::formatCurrencyOutput($unspent),
            'source' => $sourceFy,
            'target' => $targetFy,
        ]));
    }

    /**
     * Coerce a fiscal-year label to the canonical `FY2025-26` shape, or null
     * if it can't be read. Accepts the two-digit `FY25-26` form too.
     */
    private function canonicalFy(string $fy): ?string
    {
        $fy = trim($fy);

        if (preg_match('/(\d{4})\s*-\s*(\d{2})$/', $fy, $m)) {
            return 'FY'.$m[1].'-'.$m[2];
        }
        if (preg_match('/(\d{2})\s*-\s*(\d{2})$/', $fy, $m)) {
            return 'FY20'.$m[1].'-'.$m[2];
        }

        return null;
    }

    /**
     * The fiscal year before a canonical `FY2025-26` label, or null.
     */
    private function previousFiscalYear(string $fy): ?string
    {
        if (! preg_match('/^FY(\d{4})-(\d{2})$/', $fy, $m)) {
            return null;
        }

        $start = (int) $m[1] - 1;

        return 'FY'.$start.'-'.substr((string) ($start + 1), -2);
    }
}
