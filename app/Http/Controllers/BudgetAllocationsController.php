<?php

namespace App\Http\Controllers;

use App\Models\BudgetAllocation;
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
}
