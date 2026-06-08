@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('general.reports') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

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
{{-- Feature-dashboard hub: keep all five cards on a single row at desktop
     width (Bootstrap 3 has no 5-column class, so flex the row to 20% each);
     below 992px they fall back to the col-sm-6 two-up layout. --}}
<style>
@media (min-width: 992px) {
  .report-hub-row { display: flex; flex-wrap: wrap; }
  .report-hub-row > div[class*="col-"] { flex: 1 1 20%; max-width: 20%; }
}
</style>
<div class="row report-hub-row">

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

    @can('reports.contracts.view')
    <div class="col-md-3 col-sm-6">
        <a href="{{ route('reports.contracts') }}" class="small-box-link" style="text-decoration:none;">
            <div class="small-box bg-blue">
                <div class="inner">
                    <h3 style="font-size:22px;">{{ trans('admin/reports/general.hub_tile_contracts') }}</h3>
                    <p>{{ trans('admin/reports/general.hub_tile_contracts_help') }}</p>
                </div>
                <div class="icon"><i class="fas fa-file-contract" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    @endcan

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

    function renderAll() { renderBar(); renderLine(); }
    renderAll();

    new MutationObserver(renderAll).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
    });
})();
</script>
@endpush
