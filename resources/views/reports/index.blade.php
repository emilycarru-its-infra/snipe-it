@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('general.reports') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
<div class="row"><div class="col-md-12">

<div class="row">
    <div class="col-md-12">
        <p class="text-muted" style="margin-bottom:15px;">
            {{ trans('admin/reports/general.hub_intro') }}
        </p>
    </div>
</div>

{{-- Top: feature dashboards --}}
<h2 class="box-title" style="margin:0 0 10px 0; font-size:18px; padding-left:5px;">
    {{ trans('admin/reports/general.hub_section_dashboards') }}
</h2>
{{-- Feature-dashboard hub: every card the viewer can see shares one
     row at equal width and equal height (.tile-row-6, layout CSS);
     below 992px they fall back to the col-sm-6 two-up layout. --}}
<div class="row tile-row-6">

    @can('reports.procurement.view')
    <div class="col-md-3 col-sm-6">
        <a href="{{ route('reports.procurement') }}" class="small-box-link" style="text-decoration:none;">
            <div class="small-box bg-aqua">
                <div class="inner">
                    <h3 style="font-size:22px;">{{ trans('admin/reports/general.hub_tile_procurement') }}</h3>
                    <p>{{ trans('admin/reports/general.hub_tile_procurement_help') }}</p>
                </div>
                <div class="icon"><i class="fas fa-shopping-cart" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    @endcan

    {{-- Deployments follows Procurement: the physical half of the same
         refresh cycle, so the two cards read as one hand-off. --}}
    @can('view', \App\Models\Order::class)
    <div class="col-md-3 col-sm-6">
        <a href="{{ route('reports.deployments') }}" class="small-box-link" style="text-decoration:none;">
            <div class="small-box bg-navy">
                <div class="inner">
                    <h3 style="font-size:22px;">{{ trans('admin/reports/general.hub_tile_deployments') }}</h3>
                    <p>{{ trans('admin/reports/general.hub_tile_deployments_help') }}</p>
                </div>
                <div class="icon"><i class="fas fa-truck" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    @endcan

    {{-- No Contracts card: the contracts dashboard and its drill-downs were
         folded into /contracts, which owns the whole module. --}}

    @can('reports.transactions.view')
    <div class="col-md-3 col-sm-6">
        <a href="{{ route('reports.transactions.index') }}" class="small-box-link" style="text-decoration:none;">
            <div class="small-box bg-green">
                <div class="inner">
                    <h3 style="font-size:22px;">{{ trans('admin/reports/general.hub_tile_transactions') }}</h3>
                    <p>{{ trans('admin/reports/general.hub_tile_transactions_help') }}</p>
                </div>
                <div class="icon"><i class="fas fa-cash-register" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    @endcan

    @can('view', \App\Models\Asset::class)
    <div class="col-md-3 col-sm-6">
        <a href="{{ route('reports.printing') }}" class="small-box-link" style="text-decoration:none;">
            <div class="small-box bg-yellow">
                <div class="inner">
                    <h3 style="font-size:22px;">{{ trans('admin/reports/general.hub_tile_printing') }}</h3>
                    <p>{{ trans('admin/reports/general.hub_tile_printing_help') }}</p>
                </div>
                <div class="icon"><i class="fas fa-print" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    @endcan

    @can('view', \App\Models\Order::class)
    <div class="col-md-3 col-sm-6">
        <a href="{{ route('reports.exhibit') }}" class="small-box-link" style="text-decoration:none;">
            <div class="small-box bg-purple">
                <div class="inner">
                    <h3 style="font-size:22px;">{{ trans('admin/reports/general.hub_tile_exhibit') }}</h3>
                    <p>{{ trans('admin/reports/general.hub_tile_exhibit_help') }}</p>
                </div>
                <div class="icon"><i class="fas fa-palette" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    @endcan

</div>

{{-- Middle: cross-cutting graphs --}}
<div class="row">

    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">
                    <i class="fas fa-laptop" aria-hidden="true"></i>
                    {{ trans('admin/reports/general.hub_chart_fleet_refresh') }}
                </h2>
                <div class="box-tools pull-right">
                    <span class="text-muted" style="font-size:11px;">
                        {{ trans('admin/reports/general.hub_chart_fleet_refresh_help') }}
                    </span>
                </div>
            </div>
            <div class="box-body">
                <div style="position:relative; height:260px;">
                    <canvas id="chart-fleet-refresh"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">
                    <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                    {{ trans('admin/reports/general.hub_chart_contract_expiry') }}
                </h2>
                <div class="box-tools pull-right">
                    <span class="text-muted" style="font-size:11px;">
                        {{ trans('admin/reports/general.hub_chart_contract_expiry_help') }}
                    </span>
                </div>
            </div>
            <div class="box-body">
                <div style="position:relative; height:260px;">
                    <canvas id="chart-contract-expiry"></canvas>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- Lessor breakdown — the lease portfolio section, folded into the hub
     rather than a page of its own. Annual Rent leads: the per-FY leasing
     cost is the key budgeting figure. --}}
@if ($lessorBreakdown !== null)
<h2 class="box-title" id="lessor-breakdown" style="margin:15px 0 10px 0; font-size:18px; padding-left:5px;">
    {{ trans('admin/purchase-orders/general.report_lessor_breakdown') }}
</h2>
<div class="row">
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('admin/purchase-orders/general.lessor_chart_annual_rent') }}</h2>
                <div class="box-tools pull-right">
                    <span class="text-muted" style="font-size:11px;">{{ trans('admin/purchase-orders/general.lessor_chart_annual_rent_help') }}</span>
                </div>
            </div>
            <div class="box-body">
                <div style="position:relative; height:240px;">
                    <canvas id="chart-lessor-annual-rent"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('admin/purchase-orders/general.lessor_chart_cost') }}</h2>
            </div>
            <div class="box-body">
                <div style="position:relative; height:240px;">
                    <canvas id="chart-lessor-cost"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('admin/purchase-orders/general.lessor_chart_ownership') }}</h2>
            </div>
            <div class="box-body">
                <div style="position:relative; height:240px;">
                    <canvas id="chart-lessor-ownership"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-12">
        <div class="box box-default rpt-report-box">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.report_lessor_breakdown') }}</h3>
                <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ trans('admin/purchase-orders/general.report_lessor_breakdown_desc') }}</span>
                <div class="box-tools pull-right">
                    <a href="{{ route('reports.lessor-breakdown', ['format' => 'csv']) }}" class="btn btn-sm btn-default">
                        <x-icon type="download" /> {{ trans('general.download') }}
                    </a>
                </div>
            </div>
            <div class="box-body">
                @include('reports.procurement._report-table', [
                    'columns' => $lessorBreakdown['columns'],
                    'rows'    => $lessorBreakdown['records'],
                    'footer'  => $lessorBreakdown['footer'] ?? null,
                    'canEditNotes' => false,
                ])
            </div>
        </div>
    </div>
</div>
@endif

{{-- Bottom: standard report links --}}
<h2 class="box-title" style="margin:15px 0 10px 0; font-size:18px; padding-left:5px;">
    {{ trans('admin/reports/general.hub_section_reports') }}
</h2>
<div class="row" style="padding-bottom: 10px;">

    <div class="col-md-3 col-sm-6">
        <a href="{{ route('reports.activity') }}" class="btn btn-theme btn-block" style="margin-bottom: 10px; white-space: normal;">
            <x-icon type="reports"/> {{ trans('general.activity_report') }}
        </a>
    </div>

    <div class="col-md-3 col-sm-6">
        <a href="{{ url('reports/custom') }}" class="btn btn-theme btn-block" style="margin-bottom: 10px; white-space: normal;">
            <x-icon type="reports"/> {{ trans('general.custom_report') }}
        </a>
    </div>

    <div class="col-md-3 col-sm-6">
        <a href="{{ route('reports.audit') }}" class="btn btn-theme btn-block" style="margin-bottom: 10px; white-space: normal;">
            <x-icon type="audit"/> {{ trans('general.audit_report') }}
        </a>
    </div>

    <div class="col-md-3 col-sm-6">
        <a href="{{ url('reports/depreciation') }}" class="btn btn-theme btn-block" style="margin-bottom: 10px; white-space: normal;">
            <x-icon type="reports"/> {{ trans('general.depreciation_report') }}
        </a>
    </div>

    <div class="col-md-3 col-sm-6">
        <a href="{{ url('reports/licenses') }}" class="btn btn-theme btn-block" style="margin-bottom: 10px; white-space: normal;">
            <x-icon type="licenses"/> {{ trans('general.license_report') }}
        </a>
    </div>

    <div class="col-md-3 col-sm-6">
        <a href="{{ route('ui.reports.maintenances') }}" class="btn btn-theme btn-block" style="margin-bottom: 10px; white-space: normal;">
            <x-icon type="maintenances"/> {{ trans('general.asset_maintenance_report') }}
        </a>
    </div>

    <div class="col-md-3 col-sm-6">
        <a href="{{ url('reports/unaccepted_assets') }}" class="btn btn-theme btn-block" style="margin-bottom: 10px; white-space: normal;">
            <x-icon type="assets"/> {{ trans('general.unaccepted_asset_report') }}
        </a>
    </div>

    <div class="col-md-3 col-sm-6">
        <a href="{{ url('reports/accessories') }}" class="btn btn-theme btn-block" style="margin-bottom: 10px; white-space: normal;">
            <x-icon type="accessories"/> {{ trans('general.accessory_report') }}
        </a>
    </div>

    @can('reports.fleet-health.view')
    <div class="col-md-3 col-sm-6">
        <a href="{{ route('reports.fleet-health') }}" class="btn btn-theme btn-block" style="margin-bottom: 10px; white-space: normal;">
            <i class="fas fa-heartbeat fa-fw" aria-hidden="true"></i> {{ trans('admin/reports/general.hub_tile_fleet_health') }}
        </a>
    </div>
    @endcan

</div>

</div></div>
@stop


@push('css')
<style>
    .small-box-link:hover .small-box { filter: brightness(1.05); }
</style>
@endpush


@push('js')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
(function () {
    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function themeColors() {
        var dark = isDark();
        return {
            font: dark ? '#cccccc' : '#666666',
            grid: dark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
            bar:  '#3c8dbc',
            line: '#f39c12',
        };
    }

    var fleetData    = @json($fleetRefresh);
    var contractData = @json($contractExpirations);
    var charts = {};

    function renderBar() {
        var c = themeColors();
        Chart.defaults.global.defaultFontColor = c.font;
        if (charts.fleet) { charts.fleet.destroy(); }
        charts.fleet = new Chart(document.getElementById('chart-fleet-refresh'), {
            type: 'bar',
            data: {
                labels: fleetData.labels,
                datasets: [{
                    label: @json(trans('general.assets')),
                    data: fleetData.counts,
                    backgroundColor: c.bar,
                    borderColor: c.bar,
                    borderWidth: 1,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: false },
                scales: {
                    xAxes: [{ gridLines: { display: false }, ticks: { fontColor: c.font } }],
                    yAxes: [{ gridLines: { color: c.grid }, ticks: { beginAtZero: true, precision: 0, fontColor: c.font } }]
                }
            }
        });
    }

    function renderLine() {
        var c = themeColors();
        Chart.defaults.global.defaultFontColor = c.font;
        if (charts.contract) { charts.contract.destroy(); }
        charts.contract = new Chart(document.getElementById('chart-contract-expiry'), {
            type: 'line',
            data: {
                labels: contractData.labels,
                datasets: [{
                    label: @json(trans('admin/contracts/general.contracts')),
                    data: contractData.counts,
                    borderColor: c.line,
                    backgroundColor: c.line,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    fill: false,
                    tension: 0.3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: false },
                scales: {
                    xAxes: [{ gridLines: { display: false }, ticks: { fontColor: c.font } }],
                    yAxes: [{ gridLines: { color: c.grid }, ticks: { beginAtZero: true, precision: 0, fontColor: c.font } }]
                }
            }
        });
    }

    @if ($lessorBreakdown !== null)
    var lessorChart = @json($lessorBreakdown['chart']);
    // Same AdminLTE accent palette the procurement dashboard charts use.
    var lessorPalette = ['#3c8dbc', '#f39c12', '#00a65a', '#dd4b39', '#39cccc', '#605ca8', '#00c0ef', '#d81b60'];
    var money = function (value) {
        return '$' + Number(value).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };

    function renderLessor() {
        var c = themeColors();
        ['annualRent', 'cost', 'ownership'].forEach(function (key) {
            if (charts[key]) { charts[key].destroy(); }
        });

        charts.annualRent = new Chart(document.getElementById('chart-lessor-annual-rent'), {
            type: 'bar',
            data: {
                labels: lessorChart.annualRent.labels,
                datasets: [{ data: lessorChart.annualRent.data, backgroundColor: c.bar }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, legend: { display: false },
                tooltips: { callbacks: { label: function (item) { return money(item.yLabel); } } },
                scales: {
                    xAxes: [{ gridLines: { display: false }, ticks: { fontColor: c.font } }],
                    yAxes: [{ gridLines: { color: c.grid }, ticks: { beginAtZero: true, callback: money, fontColor: c.font } }]
                }
            }
        });

        charts.cost = new Chart(document.getElementById('chart-lessor-cost'), {
            type: 'doughnut',
            data: {
                labels: lessorChart.labels,
                datasets: [{ backgroundColor: lessorPalette.slice(0, lessorChart.labels.length), borderWidth: 0, data: lessorChart.cost }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                legend: { labels: { fontColor: c.font } },
                tooltips: { callbacks: { label: function (item, chartData) {
                    return chartData.labels[item.index] + ': ' + money(chartData.datasets[0].data[item.index]);
                } } }
            }
        });

        charts.ownership = new Chart(document.getElementById('chart-lessor-ownership'), {
            type: 'horizontalBar',
            data: {
                labels: lessorChart.labels,
                datasets: lessorChart.ownership.map(function (series, i) {
                    return { label: series.label, backgroundColor: lessorPalette[i % lessorPalette.length], data: series.data };
                })
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                legend: { labels: { fontColor: c.font } },
                scales: {
                    xAxes: [{ stacked: true, gridLines: { color: c.grid }, ticks: { beginAtZero: true, precision: 0, fontColor: c.font } }],
                    yAxes: [{ stacked: true, gridLines: { display: false }, ticks: { fontColor: c.font } }]
                }
            }
        });
    }
    @else
    function renderLessor() {}
    @endif

    function renderAll() { renderBar(); renderLine(); renderLessor(); }
    renderAll();

    new MutationObserver(renderAll).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
    });
})();
</script>
@endpush
