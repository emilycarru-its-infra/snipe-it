<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BudgetAllocation;
use App\Models\LeaseSchedule;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
     * Seed a fiscal year's Approved Budget from the three forecast
     * sources we can compute today:
     *
     *   - Planned orders (Order::planned() with the matching FY tag)
     *   - EOL refresh (assets with `asset_eol_date` inside the FY's
     *     window, summed by purchase_cost)
     *   - Lease renewals (LeaseSchedule with end_date inside the FY's
     *     window, summed by remaining_value or contract_total)
     *
     * Each non-zero source becomes its own row in `budget_allocations`
     * with `source=forecast`. Re-seeding the same FY first deletes all
     * existing forecast rows for that FY so the operation is idempotent
     * — supplemental and adjustment rows are NOT touched.
     */
    public function seedFromForecast(Request $request): RedirectResponse
    {
        $this->authorize('budget_allocations.manage');

        $fy = $request->validate([
            'fiscal_year' => 'required|string|max:16',
        ])['fiscal_year'];

        [$start, $end] = $this->fiscalYearWindow($fy);

        $plannedTotal = (float) Order::planned()
            ->where('fiscal_year', $fy)
            ->with('items')
            ->get()
            ->sum(fn ($order) => (float) $order->items->sum->lineTotal());

        $eolTotal = 0.0;
        if ($start && $end) {
            $eolTotal = (float) Asset::whereNotNull('asset_eol_date')
                ->whereBetween('asset_eol_date', [$start, $end])
                ->sum('purchase_cost');
        }

        $leaseRenewalTotal = 0.0;
        if ($start && $end && class_exists(LeaseSchedule::class)) {
            $column = $this->leaseValueColumn();
            if ($column) {
                $leaseRenewalTotal = (float) LeaseSchedule::query()
                    ->whereNotNull('end_date')
                    ->whereBetween('end_date', [$start, $end])
                    ->sum($column);
            }
        }

        $rows = collect([
            ['source_label' => 'planned_orders',  'amount' => $plannedTotal],
            ['source_label' => 'eol_refresh',     'amount' => $eolTotal],
            ['source_label' => 'lease_renewals',  'amount' => $leaseRenewalTotal],
        ])->filter(fn ($row) => abs($row['amount']) > 0.005);

        DB::transaction(function () use ($fy, $rows) {
            BudgetAllocation::where('fiscal_year', $fy)
                ->where('source', 'forecast')
                ->delete();

            foreach ($rows as $row) {
                BudgetAllocation::create([
                    'fiscal_year'    => $fy,
                    'area'           => null,
                    'amount'         => $row['amount'],
                    'source'         => 'forecast',
                    'description'    => trans('admin/budget-allocations/general.seed_description_'.$row['source_label']),
                    'effective_date' => now()->toDateString(),
                    'created_by'     => Auth::id(),
                ]);
            }
        });

        return redirect()
            ->route('reports.procurement', ['fiscal_year' => $fy])
            ->with('success', trans('admin/budget-allocations/general.forecast_seeded', [
                'count'  => $rows->count(),
                'total'  => number_format($rows->sum('amount'), 2),
            ]));
    }

    /**
     * Returns [start, end] dates for an FY tag like "FY2026-27"
     * assuming ECU's fiscal year (May 1 → April 30). Returns
     * [null, null] when the tag can't be parsed.
     */
    private function fiscalYearWindow(string $fy): array
    {
        if (! preg_match('/^FY(\d{4})-?(\d{2,4})?$/i', $fy, $m)) {
            return [null, null];
        }
        $startYear = (int) $m[1];
        $start = sprintf('%04d-05-01', $startYear);
        $end   = sprintf('%04d-04-30', $startYear + 1);

        return [$start, $end];
    }

    /**
     * Lease schedules don't have a uniform "value" column across forks.
     * Probe for the most likely names and pick the first that exists.
     * Returns null if none found (lease total stays 0).
     */
    private function leaseValueColumn(): ?string
    {
        foreach (['contract_total', 'remaining_value', 'total_cost', 'monthly_amount'] as $col) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('lease_schedules', $col)) {
                return $col;
            }
        }

        return null;
    }
}
