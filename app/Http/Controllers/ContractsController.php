<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
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

        $allFiscalYears = $this->fiscalYearRange();

        // Opens on the current fiscal year, same as /procurement, and moves
        // with the calendar rather than being pinned to a year. The range
        // always contains the current FY, so it never silently opens on the
        // all-time view. `?fiscal_year=all` is the opt-out.
        $rawFy = $request->query('fiscal_year');
        $currentStart = $this->fyStartYear(Helper::currentFiscalYear());
        $defaultFy = $allFiscalYears->first(fn ($l) => $this->fyStartYear($l) === $currentStart)
            ?? $allFiscalYears->last();
        if ($rawFy === 'all') {
            $selectedFy = null;
        } elseif ($rawFy !== null && $allFiscalYears->contains($rawFy)) {
            $selectedFy = $rawFy;
        } else {
            $selectedFy = $defaultFy;
        }

        $filters = [
            'fiscal_year'      => $selectedFy,
            'is_active'        => $request->query('is_active'),
            'theme'            => $request->query('theme'),
            'expiring_days'    => $request->query('expiring_within_days'),
            'top_level'        => filter_var($request->query('top_level'), FILTER_VALIDATE_BOOLEAN),
            'synthesized_only' => filter_var($request->query('synthesized_only'), FILTER_VALIDATE_BOOLEAN),
        ];

        // Tiles are mutually exclusive scopes — each link replaces the active
        // filter rather than adding to it — so each counts under the fiscal
        // year alone, which is exactly what clicking it leaves in the table.
        $scoped = fn () => $this->applyFilters(Contract::query(), $filters, except: ['is_active', 'theme', 'expiring_days', 'top_level', 'synthesized_only']);

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
            // One page, one permission. The tiles, charts, reports and
            // register are a single view of the same rows, so splitting them
            // across `contracts.view` and a second reporting permission only
            // produced a half-drawn page — and the figures they carry are
            // already in the register's own columns.
            $this->dashboardCharts($filters),
        ));
    }

    /**
     * Apply the page's filter set to a query. Everything on the page reads
     * against the same filters — the tiles, the four charts and the register
     * table — so a theme or an expiry window narrows the whole view, not just
     * the rows underneath it.
     *
     * Columns are table-qualified because the provider chart joins suppliers.
     *
     * @param  array<int, string>  $except  filter keys to skip; a chart drops
     *                                      its own grouping dimension so
     *                                      filtering by it doesn't collapse
     *                                      the chart to a single bar.
     */
    private function applyFilters($query, array $filters, array $except = [])
    {
        $wanted = fn (string $key) => ! in_array($key, $except, true);

        if ($wanted('fiscal_year') && $filters['fiscal_year']) {
            $query->where('contracts.fiscal_year', $filters['fiscal_year']);
        }

        if ($wanted('is_active') && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('contracts.is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if ($wanted('theme') && $filters['theme']) {
            $query->where('contracts.theme', $filters['theme']);
        }

        if ($wanted('expiring_days') && $filters['expiring_days']) {
            $query->expiringWithin((int) $filters['expiring_days']);
        }

        if ($wanted('top_level') && $filters['top_level']) {
            $query->topLevel();
        }

        if ($wanted('synthesized_only') && $filters['synthesized_only']) {
            $query->where('contracts.is_synthesized', true);
        }

        return $query;
    }

    /**
     * Chart series for the dashboard half of the page. Every series reads
     * against the page's active filters, so narrowing to a theme or an expiry
     * window redraws the charts rather than only the table underneath them.
     *
     * Each chart drops the one filter it groups by — spend-by-FY ignores the
     * fiscal year, by-area ignores the area — because filtering a chart by
     * its own axis collapses it to a single bar and tells you nothing.
     *
     * Series count real contracts only, unless the reader has explicitly
     * asked for the renewal series rows; a series row would otherwise show up
     * as an extra contract carrying no cost.
     */
    /**
     * The contract register starts at FY2024-25: the picker and the FY chart
     * run from there through next fiscal year regardless of which years hold
     * rows yet, in whichever label format the data already uses (TDX parses
     * short "FY24-25" labels; the app generates long "FY2024-25" ones).
     */
    private const FY_RANGE_FLOOR = 2024;

    private function fyStartYear(?string $label): ?int
    {
        if ($label && preg_match('/FY\s*(\d{4}|\d{2})/i', (string) $label, $m)) {
            $y = (int) $m[1];

            return $y < 100 ? 2000 + $y : $y;
        }

        return null;
    }

    private function fyLabel(int $startYear, bool $short): string
    {
        $end = substr((string) ($startYear + 1), -2);

        return $short
            ? 'FY'.substr((string) $startYear, -2).'-'.$end
            : 'FY'.$startYear.'-'.$end;
    }

    private function fiscalYearRange(): \Illuminate\Support\Collection
    {
        $dataYears = Contract::whereNotNull('fiscal_year')->distinct()->pluck('fiscal_year');
        $short = $dataYears->isNotEmpty()
            && $dataYears->every(fn ($v) => ! preg_match('/FY\s*\d{4}/i', (string) $v));

        $currentStart = $this->fyStartYear(Helper::currentFiscalYear());
        $maxData = $dataYears->map(fn ($v) => $this->fyStartYear($v))->filter()->max() ?? $currentStart;
        $to = max($currentStart + 1, $maxData);

        return collect(range(self::FY_RANGE_FLOOR, $to))
            ->map(fn ($y) => $this->fyLabel($y, $short));
    }

    private function dashboardCharts(array $filters): array
    {
        $base = function (array $except = []) use ($filters) {
            $query = $this->applyFilters(Contract::query(), $filters, $except);

            return $filters['synthesized_only'] ? $query : $query->realOnly();
        };

        // Booked spend, bucketed by parsed start year so short and long FY
        // labels land in the same bar, zero-filled across the whole range.
        $bookedByYear = $base(['fiscal_year'])
            ->whereNotNull('contracts.fiscal_year')
            ->selectRaw('contracts.fiscal_year AS fy, SUM(contracts.total_cost) AS total')
            ->groupBy('fy')
            ->pluck('total', 'fy')
            ->reduce(function (array $carry, $total, $fy) {
                $year = $this->fyStartYear($fy);
                if ($year !== null) {
                    $carry[$year] = ($carry[$year] ?? 0) + (float) $total;
                }

                return $carry;
            }, []);

        // The forecast leg: an active contract ending in FY X is a renewal
        // decision in FY X at today's cost, so future years show the spend
        // current commitments already imply.
        $renewalByYear = $base(['fiscal_year', 'expiring_days'])
            ->where('contracts.is_active', true)
            ->whereNotNull('contracts.end_date')
            ->where('contracts.end_date', '>=', now())
            ->selectRaw('CASE WHEN MONTH(contracts.end_date) >= 4 THEN YEAR(contracts.end_date) ELSE YEAR(contracts.end_date) - 1 END AS fy_start, SUM(contracts.total_cost) AS total')
            ->groupBy('fy_start')
            ->pluck('total', 'fy_start');

        $range = $this->fiscalYearRange();
        $currentStart = $this->fyStartYear(Helper::currentFiscalYear());
        $spendByFy = $range->mapWithKeys(fn ($label) => [$label => (float) ($bookedByYear[$this->fyStartYear($label)] ?? 0)]);
        $forecastByFy = $range->mapWithKeys(function ($label) use ($renewalByYear, $currentStart) {
            $year = $this->fyStartYear($label);

            return [$label => $year >= $currentStart ? (float) ($renewalByYear[$year] ?? 0) : 0.0];
        });

        $countByTheme = $base(['theme'])
            ->whereNotNull('contracts.theme')
            ->selectRaw('contracts.theme AS theme, COUNT(*) AS n')
            ->groupBy('theme')
            ->orderByDesc('n')
            ->pluck('n', 'theme');

        // Left join, not inner: contracts with no supplier are common, and an
        // inner join silently renders the whole chart empty when a fiscal
        // year happens to hold none that are linked to one.
        $spendByProvider = $base()
            ->leftJoin('suppliers', 'suppliers.id', '=', 'contracts.supplier_id')
            ->selectRaw('COALESCE(suppliers.name, "—") AS provider, SUM(contracts.total_cost) AS total')
            ->groupBy('provider')
            ->havingRaw('SUM(contracts.total_cost) > 0')
            ->orderByDesc('total')
            ->limit(10)
            ->pluck('total', 'provider');

        // The expiry filter is dropped here: a 30-day window would leave this
        // 12-month calendar with a single month in it.
        $renewalCalendar = $base(['expiring_days'])
            ->where('contracts.is_active', true)
            ->whereNotNull('contracts.end_date')
            ->whereBetween('contracts.end_date', [now()->startOfMonth(), now()->addYear()->endOfMonth()])
            ->selectRaw("DATE_FORMAT(contracts.end_date, '%Y-%m') AS ym, COUNT(*) AS n")
            ->groupBy('ym')
            ->orderBy('ym')
            ->pluck('n', 'ym');

        return [
            'charts' => [
                'fyLabels'       => $spendByFy->keys()->all(),
                'fyValues'       => array_values($spendByFy->all()),
                'fyForecast'     => array_values($forecastByFy->all()),
                'providerLabels' => $spendByProvider->keys()->all(),
                'providerValues' => array_values($spendByProvider->all()),
                'themeLabels'    => $countByTheme->keys()->all(),
                'themeValues'    => array_values($countByTheme->all()),
                'themeSelected'  => $filters['theme'],
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
