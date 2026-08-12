@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/purchase-orders/general.dashboard_title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

{{-- The working-tool doors left, the FY scope right and prominent —
     the breadcrumb directly above already names the module. --}}
<div style="display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:15px;">
    <span class="hidden-print" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
        <a href="{{ route('purchase-orders.builder', ['fiscal_year' => $selectedFy ?? 'all']) }}" class="btn btn-sm btn-default">
            {{ trans('admin/purchase-orders/general.report_po_builder') }}
        </a>
        <a href="{{ route('reports.procurement.capital-request', array_filter(['fiscal_year' => $selectedFy])) }}" class="btn btn-sm btn-default">
            {{ trans('admin/purchase-orders/general.capital_request_title') }}
        </a>
        <a href="{{ route('requisitions.index') }}" class="btn btn-sm btn-default">
            {{ trans('admin/purchase-orders/general.requisitions') }}
        </a>
        <a href="{{ route('store.index') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.go_store') }}</a>
        @can('procurement.edit')
            <a href="{{ route('procurement.store-admin') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.go_store_admin') }}</a>
        @endcan
        @if (auth()->user()->isSuperUser())
            <button type="button" class="btn btn-sm btn-default" data-toggle="modal" data-target="#approversModal">
                {{ trans('admin/store/general.approvers_title') }}
            </button>
        @endif
    </span>
    {{-- Switching FY reloads the page; stash the scroll position so the
         reader lands back where they were, not at the top. --}}
    <form method="get" style="margin:0 0 0 auto;">
        <select name="fiscal_year" class="form-control" style="width:auto; font-weight:700;"
                onchange="sessionStorage.setItem('procDashScroll', String(window.scrollY)); this.form.submit()">
            <option value="all" {{ $selectedFy === null ? 'selected' : '' }}>{{ trans('admin/purchase-orders/general.all_fiscal_years') }}</option>
            @foreach ($allFiscalYears as $fy)
                <option value="{{ $fy }}" {{ $selectedFy === $fy ? 'selected' : '' }}>{{ $fy }}</option>
            @endforeach
        </select>
    </form>
</div>

<style>
    @media (min-width: 992px) {
        .proc-chart-row .col-md-3 { width: 16.6667%; }
    }
    .proc-chart-row .box-body { height: 220px; }
</style>
<div class="row proc-chart-row">
    <div class="col-md-3 col-sm-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_po') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:200px;">
                    <canvas id="procPoChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_utilization') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:200px;">
                    <canvas id="procUtilChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_fiscal_year') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:200px;">
                    <canvas id="procFyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_monthly') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:200px;">
                    <canvas id="procMonthlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    {{-- Lease returns, financial lens: pending decisions and credits at a
         glance. The physical collection/wipe/pack work lives on the
         Deployments board's decommissioning lane. --}}
    <div class="col-md-3 col-sm-6 proc-pipe">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.returns_card_title') }}</h3>
            </div>
            <div class="box-body" style="overflow:auto;">
                @php
                    $returnsPending = count($pipeline['returns']['pending']['cards']) + $pipeline['returns']['pending']['more'];
                    $returnsClosed = count($pipeline['returns']['closed']['cards']) + $pipeline['returns']['closed']['more'];
                @endphp
                <div style="margin-bottom:8px;">
                    <a href="#proc-report_lease_decisions" class="pr-jump" data-pr-jump="proc-report_lease_decisions" style="font-weight:700;">
                        {{ trans('admin/purchase-orders/general.pipeline_returns_pending') }} · {{ $returnsPending }}
                    </a>
                    <div style="margin-top:4px;">
                        @foreach (array_slice($pipeline['returns']['pending']['cards']->all(), 0, 3) as $returnRow)
                            <span class="label label-default" style="display:inline-block; margin:0 4px 4px 0;">{{ $returnRow['contract_reference'] }}</span>
                        @endforeach
                    </div>
                </div>
                <div style="margin-bottom:8px;">
                    <a href="#proc-report_credit_ledger" class="pr-jump" data-pr-jump="proc-report_credit_ledger" style="font-weight:700;">
                        {{ trans('admin/purchase-orders/general.pipeline_returns_closed') }} · {{ $returnsClosed }}
                    </a>
                </div>
                <p class="text-muted" style="font-size:11.5px; margin:0;">
                    <a href="{{ route('reports.deployments') }}#decommissioning">{{ trans('admin/purchase-orders/general.pipeline_returns_physical_link') }}</a>
                </p>
            </div>
        </div>
    </div>
    {{-- The unfunded counterweight to the funded pipeline: no replacement
         money was pre-approved for these devices. One section per family
         (Legacy, Buyouts), each sliceable by model. --}}
    <div class="col-md-3 col-sm-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/deployments/general.legacy_box_title') }}
                    <span class="text-muted" style="font-weight:normal;">· {{ $legacyFleet['count'] ?? 0 }} · ${{ \App\Helpers\Helper::formatCurrencyOutput($legacyFleet['purchase_cost'] ?? 0) }}</span>
                </h3>
            </div>
            <div class="box-body" style="overflow:auto;">
                <p class="text-muted" style="font-size:11.5px; margin:0 0 8px;">{{ trans('admin/deployments/general.legacy_box_subtitle') }}</p>
                @forelse ($legacyFleet['sections'] ?? [] as $section)
                    <div style="margin-bottom:10px;">
                        <a href="{{ url('hardware') }}?status_id={{ $section['status_ids'][0] }}" style="font-weight:700;">
                            {{ $section['label'] }} · {{ $section['count'] }} · ${{ \App\Helpers\Helper::formatCurrencyOutput($section['purchase_cost']) }}
                        </a>
                        <div class="text-muted" style="font-size:11.5px;">
                            {{ trans('admin/deployments/general.legacy_age_note', [
                                'age' => $section['avg_age_years'] ?? '—',
                                'oldest' => $section['oldest_year'] ?? '—',
                            ]) }}
                        </div>
                        <div style="margin-top:4px;">
                            @foreach (array_slice($section['by_model'], 0, 4) as $legacyRow)
                                <a href="{{ url('hardware') }}?status_id={{ $section['status_ids'][0] }}&model_id={{ $legacyRow['model_id'] }}"
                                   class="label label-default" style="display:inline-block; margin:0 4px 4px 0;">
                                    {{ $legacyRow['model'] }} · {{ $legacyRow['count'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-muted" style="margin:0;">—</p>
                @endforelse
                <p class="text-muted" style="font-size:11.5px; margin:0;">{{ trans('admin/deployments/general.legacy_note') }}</p>
            </div>
        </div>
    </div>
</div>



@php
    $hiddenReports = (array) (auth()->user()?->hidden_procurement_reports ?? []);

    // Fiscal-year scope is global and sticky (session-backed), so it's
    // safe to carry the current selection onto every report link;
    // reports that read fiscal_year with other semantics ignore it.
    $fyParam = $selectedFy ?? 'all';
    $reportLink = fn ($route, $extra = []) => route($route, array_merge(['fiscal_year' => $fyParam], $extra));

    // One list drives both the sticky jump-nav and the inline tables.
    // Ordered to follow the money: budget envelope first, then spend
    // commitments, then invoices, then the lease intake-to-disposition
    // lifecycle, with the remaining working/audit views after.
    $procReports = collect([
        ['route' => 'reports.procurement.po-budget', 'name' => 'report_po_budget', 'desc' => 'report_po_budget_desc', 'stage' => 'budgeting'],
        ['route' => 'reports.procurement.lease-end-schedules', 'name' => 'report_lease_end_schedules', 'desc' => 'report_lease_end_schedules_desc', 'stage' => 'budgeting'],
        ['route' => 'reports.procurement.aro-register', 'name' => 'report_aro_register', 'desc' => 'report_aro_register_desc', 'stage' => 'reconciling'],
        ['route' => 'reports.procurement.extension-watch', 'name' => 'report_extension_watch', 'desc' => 'report_extension_watch_desc', 'stage' => 'budgeting'],
        ['route' => 'reports.procurement.invoices', 'name' => 'report_invoices', 'desc' => 'report_invoices_desc', 'stage' => 'reconciling'],
        ['route' => 'reports.procurement.invoice-approval', 'name' => 'report_invoice_approval', 'desc' => 'report_invoice_approval_desc', 'stage' => 'reconciling'],
        ['route' => 'reports.procurement.csi-arrivals', 'name' => 'report_csi_arrivals', 'desc' => 'report_csi_arrivals_desc', 'stage' => 'deploying'],
        ['route' => 'reports.procurement.schedule-signing', 'name' => 'report_schedule_signing', 'desc' => 'report_schedule_signing_desc', 'stage' => 'reconciling'],
        ['route' => 'reports.procurement.csi-schedule', 'name' => 'report_csi_schedule', 'desc' => 'report_csi_schedule_desc', 'stage' => 'reconciling'],
        ['route' => 'reports.procurement.leases-operational', 'name' => 'report_leases_operational', 'desc' => 'report_leases_operational_desc', 'stage' => 'deploying'],
        ['route' => 'reports.procurement.leases-financial', 'name' => 'report_leases_financial', 'desc' => 'report_leases_financial_desc', 'stage' => 'budgeting'],
        ['route' => 'reports.procurement.rent-costs', 'name' => 'report_rent_costs', 'desc' => 'report_rent_costs_desc', 'stage' => 'budgeting'],
        ['route' => 'reports.procurement.disposition-grid', 'name' => 'report_disposition_grid', 'desc' => 'report_disposition_grid_desc', 'stage' => 'deploying'],
        ['route' => 'reports.procurement.po-disposition', 'name' => 'report_po_disposition', 'desc' => 'report_po_disposition_desc', 'stage' => 'completed'],
        ['route' => 'reports.procurement.forecast', 'name' => 'report_forecast', 'desc' => 'report_forecast_desc', 'stage' => 'budgeting'],
        ['route' => 'reports.procurement.user-agreement-ledger', 'name' => 'report_user_agreement_ledger', 'desc' => 'report_user_agreement_ledger_desc', 'stage' => 'deploying'],
        ['route' => 'reports.procurement.csi-reconciliation', 'name' => 'report_csi_reconciliation', 'desc' => 'report_csi_reconciliation_desc', 'stage' => 'reconciling'],
        ['route' => 'reports.procurement.lease-data-health', 'name' => 'report_lease_data_health', 'desc' => 'report_lease_data_health_desc', 'stage' => 'reconciling'],
        ['route' => 'reports.procurement.lease-decisions', 'name' => 'report_lease_decisions', 'desc' => 'report_lease_decisions_desc', 'stage' => 'deploying'],
        ['route' => 'reports.procurement.asset-lease-detail', 'name' => 'report_asset_lease_detail', 'desc' => 'report_asset_lease_detail_desc', 'stage' => 'deploying'],
        ['route' => 'reports.procurement.po-drilldown', 'name' => 'report_po_drilldown', 'desc' => 'report_po_drilldown_desc', 'stage' => 'ordering'],
        ['route' => 'reports.procurement.credit-ledger', 'name' => 'report_credit_ledger', 'desc' => 'report_credit_ledger_desc', 'stage' => 'completed'],
        ['route' => 'reports.procurement.pst-applicability', 'name' => 'report_pst_applicability', 'desc' => 'report_pst_applicability_desc', 'stage' => 'completed'],
        ['route' => 'reports.procurement.capital', 'name' => 'report_capital', 'desc' => 'report_capital_desc', 'stage' => 'completed'],
    ])->reject(fn ($r) => in_array($r['name'], $hiddenReports, true));
@endphp

@include('reports.procurement._pipeline')

@include('procurement._approvers-modal')




<style>
    html { scroll-behavior: smooth; }
    /* AdminLTE wraps the page in `.wrapper { overflow: hidden }` (and
       `.content-wrapper`), and an overflow ancestor traps position:sticky —
       the sticky nav can't pin to the viewport, so it just scrolls away.
       Lift the clip on this page so sticky resolves against the body scroll. */
    .wrapper, .content-wrapper { overflow: visible !important; }
    /* Flex row so the nav column stretches to the full height of the tables
       column — that's what gives position:sticky room to stay pinned through
       the whole scroll instead of stopping at the short nav box. */
    .proc-reports-row { display: flex; flex-wrap: wrap; }
    /* Narrow jump-nav (~44% slimmer than the old col-md-3) so the report
       tables get the width back. The content column flexes to fill the rest. */
    .proc-reports-row .proc-nav-col { flex: 0 0 14%; max-width: 14%; padding-left: 15px; padding-right: 15px; }
    .proc-reports-row .proc-content-col { flex: 1 1 0%; max-width: 86%; padding-left: 15px; padding-right: 15px; }
    {{-- Pin below the sticky header (round 9) — top: 16px slid the box's
         head underneath it, clipping the first entries and over-scrolling. --}}
    .proc-report-nav {
        position: sticky;
        top: calc(var(--header-h, 68px) + 12px);
        max-height: calc(100vh - var(--header-h, 68px) - 24px);
        overflow-y: auto;
    }
    {{-- AdminLTE's .box .nav-stacked ships hard-coded light values
         (#f4f4f4 row borders, #444 links) — white hairlines and near-black
         text in dark mode. Key both on the theme tokens. --}}
    .proc-report-navlist > li { border-bottom: 1px solid var(--box-header-bottom-border-color, #f4f4f4) !important; }
    .proc-report-navlist > li:last-child { border-bottom: 0 !important; }
    .proc-report-navlist > li > a { padding: 6px 10px; font-size: 12.5px; border-radius: 0; color: var(--color-fg, #444) !important; }
    .proc-report-navlist > li.active > a { color: #fff !important; }
    .proc-report-navlist > li.active > a,
    .proc-report-navlist > li.active > a:hover { background-color: #3c8dbc; color: #fff; }
    /* Each report box pins its header — name, tools AND subtitle — just
       below the app bar while its rows scroll under it; the table's thead
       pins directly beneath (offset measured live by _report-sticky-js). */
    .proc-report-box > .box-header {
        position: sticky;
        top: var(--header-h, 68px);
        z-index: 8;
        background: var(--box-bg, #fff);
    }
    .proc-report-desc { margin: 8px 0 0; font-size: 12.5px; clear: both; }
    @media (max-width: 991px) {
        .proc-reports-row { display: block; }
        .proc-reports-row .proc-content-col { max-width: 100%; }
    }
    /* Static dashboard tiles — every card is the same height regardless of how
       much text sits under the number, so wrapping text never reflows the grid.
       Flexbox equalises each wrapped line of cards; the min-height keeps single-
       and double-line cards identical. */
    .proc-card-row { display: flex; flex-wrap: wrap; }
    .proc-card-row > [class*="col-"] { display: flex; margin-bottom: 15px; }
    .proc-card-row .small-box-link { display: flex; width: 100%; }
    .proc-card-row .small-box { width: 100%; min-height: 104px; margin-bottom: 0; display: flex; flex-direction: column; justify-content: center; }
    .proc-card-row .small-box > .inner { width: 100%; }
</style>

@can('budget_allocations.manage')
@include('reports.partials.budget-allocations-modal', [
    'allocations'        => $allocations,
    'liveCarry'          => $liveCarry,
    'selectedFy'         => $selectedFy,
    'allFiscalYears'     => $allFiscalYears,
    'budgetSourceLabels' => $budgetSourceLabels,
])
@endcan
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
    // Land back where the reader was after an FY switch (the select stashes
    // scrollY before submitting). Instant jump — the page-level smooth
    // scroll-behavior would animate from the top otherwise.
    (function () {
        var saved = sessionStorage.getItem('procDashScroll');
        if (saved === null) { return; }
        sessionStorage.removeItem('procDashScroll');
        window.scrollTo({ top: parseInt(saved, 10) || 0, behavior: 'instant' });
    })();
</script>
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
    (function () {
        if (typeof Chart === 'undefined') { return; }

        var data = {!! json_encode([
            'poLabels' => array_column($poRows, 'po_number'),
            'poBudget' => array_column($poRows, 'budget'),
            'poCommitted' => array_column($poRows, 'committed'),
            'committed' => $totalCommitted,
            'remaining' => max($totalRemaining, 0),
            'fyLabels' => $fiscalYears,
            'fyCommitted' => array_map(fn ($fy) => $committedByFy[$fy] ?? 0, $fiscalYears),
            'fyPlanned' => array_map(fn ($fy) => $plannedByFy[$fy] ?? 0, $fiscalYears),
            'fyLeaseEnding' => array_map(fn ($fy) => $leaseExpiryByFy[$fy]['cost'] ?? 0, $fiscalYears),
            'monthlyLabels' => $monthlyLabels,
            'monthlyValues' => $monthlyValues,
        ]) !!};

        var money = function (value) {
            return '$' + Number(value).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        };
        var barTooltip = { callbacks: { label: function (item, data) {
            return data.datasets[item.datasetIndex].label + ': ' + money(item.yLabel);
        } } };
        var pieTooltip = { callbacks: { label: function (item, data) {
            return data.labels[item.index] + ': ' + money(data.datasets[0].data[item.index]);
        } } };

        // Axis/legend ink and grid lines follow the app's data-theme so the
        // charts stay legible in dark mode. The Planned navy bar also swaps
        // for a lighter step — #001f3f vanishes on a dark surface.
        function isDark() {
            return document.documentElement.getAttribute('data-theme') === 'dark';
        }
        function inkColor() { return isDark() ? '#c8ced6' : '#666'; }
        function gridColor() { return isDark() ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.1)'; }
        function plannedColor() { return isDark() ? '#5f7ea6' : '#001f3f'; }

        function moneyAxis() {
            return {
                ticks: { beginAtZero: true, callback: money, fontColor: inkColor() },
                gridLines: { color: gridColor(), zeroLineColor: gridColor() }
            };
        }
        function labelAxis() {
            return {
                ticks: { fontColor: inkColor() },
                gridLines: { color: gridColor(), zeroLineColor: gridColor() }
            };
        }
        function legend() { return { labels: { fontColor: inkColor() } }; }

        var charts = [];

        charts.push(new Chart(document.getElementById('procPoChart'), {
            type: 'bar',
            data: {
                labels: data.poLabels,
                datasets: [
                    { label: @json(trans('admin/purchase-orders/general.card_budget')), backgroundColor: '#00c0ef', data: data.poBudget },
                    { label: @json(trans('admin/purchase-orders/general.card_committed')), backgroundColor: '#f39c12', data: data.poCommitted }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTooltip, legend: legend(), scales: { yAxes: [moneyAxis()], xAxes: [labelAxis()] } }
        }));

        charts.push(new Chart(document.getElementById('procUtilChart'), {
            type: 'doughnut',
            data: {
                labels: [@json(trans('admin/purchase-orders/general.card_committed')), @json(trans('admin/purchase-orders/general.card_remaining'))],
                datasets: [{ backgroundColor: ['#f39c12', '#00a65a'], borderWidth: 0, data: [data.committed, data.remaining] }]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: pieTooltip, legend: legend() }
        }));

        var fyChart = new Chart(document.getElementById('procFyChart'), {
            type: 'bar',
            data: {
                labels: data.fyLabels,
                datasets: [
                    { label: @json(trans('admin/purchase-orders/general.card_committed')), backgroundColor: '#f39c12', data: data.fyCommitted },
                    { label: @json(trans('admin/purchase-orders/general.card_forecast')), backgroundColor: plannedColor(), data: data.fyPlanned },
                    { label: @json(trans('admin/purchase-orders/general.chart_lease_ending')), backgroundColor: '#39cccc', data: data.fyLeaseEnding }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTooltip, legend: legend(), scales: { yAxes: [moneyAxis()], xAxes: [labelAxis()] } }
        });
        charts.push(fyChart);

        charts.push(new Chart(document.getElementById('procMonthlyChart'), {
            type: 'line',
            data: {
                labels: data.monthlyLabels,
                datasets: [{
                    label: @json(trans('admin/purchase-orders/general.card_invoiced')),
                    borderColor: '#3c8dbc',
                    backgroundColor: 'rgba(60,141,188,0.15)',
                    data: data.monthlyValues
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTooltip, legend: legend(), scales: { yAxes: [moneyAxis()], xAxes: [labelAxis()] } }
        }));

        // Re-theme in place when the user flips the dark-mode toggle.
        new MutationObserver(function () {
            charts.forEach(function (chart) {
                chart.options.legend.labels.fontColor = inkColor();
                (chart.options.scales && chart.options.scales.yAxes || []).forEach(function (axis) {
                    axis.ticks.fontColor = inkColor();
                    axis.gridLines.color = gridColor();
                    axis.gridLines.zeroLineColor = gridColor();
                });
                (chart.options.scales && chart.options.scales.xAxes || []).forEach(function (axis) {
                    axis.ticks.fontColor = inkColor();
                    axis.gridLines.color = gridColor();
                    axis.gridLines.zeroLineColor = gridColor();
                });
                chart.update();
            });
            fyChart.data.datasets[1].backgroundColor = plannedColor();
            fyChart.update();
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    })();

    // Per-user show/hide for the procurement reports list. Each click
    // PATCHes the user's full hidden list to the visibility endpoint and
    // reloads. Persists across sessions via users.hidden_procurement_reports.
    (function () {
        var url = @json(route('reports.procurement.visibility'));
        var csrf = @json(csrf_token());
        var hidden = @json($hiddenReports);

        function save(list) {
            return fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ hidden: list })
            }).then(function () { window.location.reload(); });
        }

        document.querySelectorAll('.hide-procurement-report').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                var name = el.dataset.report;
                if (hidden.indexOf(name) !== -1) return;
                save(hidden.concat([name]));
            });
        });

        var showAll = document.getElementById('show-all-procurement-reports');
        if (showAll) {
            showAll.addEventListener('click', function (e) {
                e.preventDefault();
                save([]);
            });
        }
    })();

    // Lazy-load each report's table inline. Loaded one at a time (rather than
    // ~20 parallel queries) so heavy reports don't stampede the server; each
    // section fills in as it arrives.
    (function () {
        var pills = document.querySelectorAll('.pr-pill[data-pr-report]');
        if (! pills.length) { return; }

        // Pills are filters: none active shows every report; any active
        // set shows only those. The pipeline's stage filter composes on
        // top via the shared [data-report-stage] hidden toggling.
        function applyPills() {
            var active = Array.prototype.filter.call(pills, function (pill) {
                return pill.classList.contains('active');
            }).map(function (pill) { return pill.dataset.prReport; });

            document.querySelectorAll('.pr-report-pane').forEach(function (pane) {
                pane.classList.toggle('pr-hidden', active.length > 0 && active.indexOf(pane.id) === -1);
            });
        }

        pills.forEach(function (pill) {
            pill.addEventListener('click', function (e) {
                e.preventDefault();
                pill.classList.toggle('active');
                applyPills();
                syncHash();
            });
        });

        // The filter state is a URL: #stage=<key>&reports=<id,id> — copy
        // the address bar and the recipient lands on the same slice.
        function syncHash() {
            var parts = [];
            if (window.ppCurrentStage) { parts.push('stage=' + window.ppCurrentStage); }
            var active = Array.prototype.filter.call(pills, function (pill) {
                return pill.classList.contains('active');
            }).map(function (pill) { return pill.dataset.prReport; });
            if (active.length) { parts.push('reports=' + active.join(',')); }
            history.replaceState(null, '', parts.length ? '#' + parts.join('&') : location.pathname + location.search);
        }
        window.prSyncHash = syncHash;
        window.prClearPills = function () {
            pills.forEach(function (pill) { pill.classList.remove('active'); });
            applyPills();
        };

        (function restoreFromHash() {
            var hash = location.hash.replace(/^#/, '');
            if (! hash) { return; }
            var params = {};
            hash.split('&').forEach(function (part) {
                var kv = part.split('=');
                params[kv[0]] = decodeURIComponent(kv[1] || '');
            });
            if (params.stage) {
                var chev = document.querySelector('.pp-chev[data-pp-stage="' + params.stage + '"]');
                if (chev) { chev.click(); }
            }
            if (params.reports) {
                var wanted = params.reports.split(',');
                pills.forEach(function (pill) {
                    pill.classList.toggle('active', wanted.indexOf(pill.dataset.prReport) !== -1);
                });
                applyPills();
            }
        })();

        // Deep links (the Lease Returns card): select exactly that
        // report's pill and land on it.
        document.querySelectorAll('.pr-jump[data-pr-jump]').forEach(function (jump) {
            jump.addEventListener('click', function (e) {
                e.preventDefault();
                var id = jump.dataset.prJump;
                pills.forEach(function (pill) {
                    pill.classList.toggle('active', pill.dataset.prReport === id);
                });
                applyPills();
                syncHash();
                var pane = document.getElementById(id);
                if (pane) { pane.scrollIntoView({behavior: 'smooth', block: 'start'}); }
            });
        });

        // Every report loads up front, one after another — filters only
        // ever show and hide already-rendered content.
        var queue = Array.prototype.slice.call(document.querySelectorAll('.proc-report-body[data-embed-url]'));
        (function drain() {
            var el = queue.shift();
            if (! el) { return; }
            fetch(el.dataset.embedUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (resp) {
                    if (! resp.ok) { throw new Error('HTTP ' + resp.status); }
                    return resp.text();
                })
                .then(function (html) { el.innerHTML = html; })
                .catch(function () {
                    el.innerHTML = '<p class="text-danger">' + @json(trans('general.something_went_wrong')) + '</p>';
                })
                .then(drain);
        })();
    })();


</script>
{{-- Delegated handlers so the lazy-loaded Per-Serial Disposition Grid stays
     editable once it is injected into its report box. --}}
@include('reports.procurement._disposition-grid-js')
{{-- And the inline-editable note cells in the other report tables. --}}
@include('reports.procurement._report-note-js')
@include('reports.procurement._report-children-js')
@include('reports.procurement._report-sticky-js')
@stop
