<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Web controller for Contracts — the licenses-side analogue of the
 * Orders procurement module. TDX is the upstream source, but rows can
 * also be created or edited manually via this controller.
 */
class ContractsController extends Controller
{
    /**
     * The contracts page: tiles, charts, the drill-down reports and the
     * register table, in that order. This used to be two pages — a
     * dashboard at /reports/contracts and a bare table here — which split
     * the same data across two URLs and gave "umbrella" two different
     * meanings. One page now, and the drill-downs it embeds keep their own
     * URLs under /contracts/* (see ContractReportsController).
     *
     * @throws AuthorizationException
     */
    public function index(Request $request): View
    {
        $this->authorize('view', Contract::class);

        $allFiscalYears = Contract::whereNotNull('fiscal_year')
            ->distinct()->orderBy('fiscal_year')->pluck('fiscal_year');

        // Unlike the old dashboard, this page defaults to every fiscal year:
        // it is the contract register as well as the dashboard, and opening
        // it pre-filtered would hide most rows from anyone who came here to
        // look a contract up. Narrowing to an FY is one click away.
        $rawFy = $request->query('fiscal_year');
        $selectedFy = $allFiscalYears->contains($rawFy) ? $rawFy : null;

        $scoped = fn () => Contract::query()->when($selectedFy, fn ($q) => $q->where('fiscal_year', $selectedFy));

        // Tiles are filters over the table below, so each counts exactly what
        // clicking it leaves in the table — synthesized rows included.
        $totalCount       = $scoped()->count();
        $activeCount      = $scoped()->active()->count();
        $expiring30       = $scoped()->expiringWithin(30)->count();
        $expiring90       = $scoped()->expiringWithin(90)->count();
        $renewalSeriesCount = $scoped()->where('is_synthesized', true)->count();

        // Spend is a figure rather than a filter, so it excludes the
        // synthesized rollup rows — those carry no cost of their own and
        // exist only to group their fiscal-year children.
        $totalCost = (float) $scoped()->realOnly()->sum('total_cost');

        $themes = $scoped()
            ->select('theme')
            ->whereNotNull('theme')
            ->where('theme', '!=', '')
            ->selectRaw('COUNT(*) AS n')
            ->groupBy('theme')
            ->orderByDesc('n')
            ->limit(6)
            ->get();

        return view('contracts.index', array_merge(
            compact(
                'allFiscalYears',
                'selectedFy',
                'totalCount',
                'activeCount',
                'expiring30',
                'expiring90',
                'renewalSeriesCount',
                'totalCost',
                'themes',
            ),
            // The charts and the embedded report sections are the reporting
            // half of the page and stay behind the reports permission.
            auth()->user()?->can('reports.contracts.view')
                ? $this->dashboardCharts($selectedFy)
                : ['charts' => null],
        ));
    }

    /**
     * Chart series for the dashboard half of the page. Every series counts
     * real contracts only — a renewal series row would otherwise show up as
     * an extra contract with no cost, theme totals included.
     */
    private function dashboardCharts(?string $selectedFy): array
    {
        // Spend by fiscal year is the one series that ignores the selected
        // FY: its whole job is to put the years side by side.
        $spendByFy = Contract::realOnly()
            ->whereNotNull('fiscal_year')
            ->selectRaw('fiscal_year, SUM(total_cost) AS total')
            ->groupBy('fiscal_year')
            ->orderBy('fiscal_year')
            ->pluck('total', 'fiscal_year');

        $countByTheme = Contract::realOnly()
            ->whereNotNull('theme')
            ->when($selectedFy, fn ($q) => $q->where('fiscal_year', $selectedFy))
            ->selectRaw('theme, COUNT(*) AS n')
            ->groupBy('theme')
            ->orderByDesc('n')
            ->pluck('n', 'theme');

        $spendByProvider = Contract::realOnly()
            ->join('suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
            ->when($selectedFy, fn ($q) => $q->where('contracts.fiscal_year', $selectedFy))
            ->selectRaw('suppliers.name AS provider, SUM(contracts.total_cost) AS total')
            ->groupBy('suppliers.name')
            ->orderByDesc('total')
            ->limit(10)
            ->pluck('total', 'provider');

        $renewalCalendar = Contract::realOnly()
            ->where('is_active', true)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfMonth(), now()->addYear()->endOfMonth()])
            ->selectRaw("DATE_FORMAT(end_date, '%Y-%m') AS ym, COUNT(*) AS n")
            ->groupBy('ym')
            ->orderBy('ym')
            ->pluck('n', 'ym');

        return [
            'charts' => [
                'fyLabels'       => $spendByFy->keys()->all(),
                'fyValues'       => array_values($spendByFy->all()),
                'providerLabels' => $spendByProvider->keys()->all(),
                'providerValues' => array_values($spendByProvider->all()),
                'themeLabels'    => $countByTheme->keys()->all(),
                'themeValues'    => array_values($countByTheme->all()),
                'renewalLabels'  => $renewalCalendar->keys()->all(),
                'renewalValues'  => array_values($renewalCalendar->all()),
            ],
        ];
    }

    public function create(): View
    {
        $this->authorize('create', Contract::class);

        return view('contracts.edit')->with('item', new Contract);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Contract::class);

        $contract = new Contract;
        $this->fillFromRequest($contract, $request);
        $contract->source = $contract->source ?: 'manual';
        $contract->created_by = auth()->id();

        if ($contract->save()) {
            return redirect()->route('contracts.index')->with('success', trans('admin/contracts/message.create.success'));
        }

        return redirect()->back()->withInput()->withErrors($contract->getErrors());
    }

    public function show(Contract $contract): View
    {
        $this->authorize('view', Contract::class);

        $contract->load(['supplier', 'parent', 'children', 'licenses', 'assets', 'serials', 'attributes', 'adminuser', 'owner']);

        return view('contracts.view', compact('contract'));
    }

    public function edit(Contract $contract): View
    {
        $this->authorize('update', Contract::class);

        return view('contracts.edit')->with('item', $contract);
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $this->fillFromRequest($contract, $request);

        if ($contract->save()) {
            return redirect()->route('contracts.show', $contract)->with('success', trans('admin/contracts/message.update.success'));
        }

        return redirect()->back()->withInput()->withErrors($contract->getErrors());
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        $this->authorize('delete', Contract::class);

        $contract->delete();

        return redirect()->route('contracts.index')->with('success', trans('admin/contracts/message.delete.success'));
    }

    private function fillFromRequest(Contract $contract, Request $request): void
    {
        foreach ([
            'contract_number', 'name', 'theme', 'product', 'fiscal_year',
            'supplier_id', 'parent_contract_id', 'type', 'workflow_status',
            'start_date', 'end_date', 'total_cost', 'currency',
            'description', 'comments_review', 'gl_code',
            'requisition_number', 'voucher_number', 'service_offering',
            'ticket_url', 'schedule_number', 'notes',
        ] as $field) {
            $contract->{$field} = $request->input($field, $contract->{$field});
        }

        $contract->is_active = $request->boolean('is_active', $contract->is_active ?? true);
    }
}
