@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ $reportTitle }}
    @parent
@stop

{{-- Page-header actions --}}
@section('header_right')
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
@stop

{{-- Page content --}}
@section('content')

<div class="row">
    <div class="col-md-4 col-sm-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.lessor_chart_cost') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:220px;">
                    <canvas id="lessorCostChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.lessor_chart_devices') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:220px;">
                    <canvas id="lessorDevicesChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.lessor_chart_ownership') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:220px;">
                    <canvas id="lessorOwnershipChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default rpt-report-box">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $reportTitle }}</h3>
                <p style="margin:4px 0 0; font-weight:400;">{{ trans('admin/purchase-orders/general.report_lessor_breakdown_desc') }}</p>
            </div>
            <div class="box-body">
                @include('reports.procurement._report-table', [
                    'columns' => $columns,
                    'rows'    => $rows,
                    'footer'  => $footer ?? null,
                    'canEditNotes' => false,
                ])
            </div>
        </div>
    </div>
</div>
@include('reports.procurement._report-sticky-js')
@stop

@section('moar_scripts')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
    (function () {
        if (typeof Chart === 'undefined') { return; }

        var data = {!! json_encode($chart) !!};
        // Same AdminLTE accent palette the procurement dashboard charts use.
        var palette = ['#3c8dbc', '#f39c12', '#00a65a', '#dd4b39', '#39cccc', '#605ca8', '#00c0ef', '#d81b60'];

        var money = function (value) {
            return '$' + Number(value).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        };

        // Axis/legend ink and grid lines follow the app's data-theme so the
        // charts stay legible in dark mode (same pattern as the procurement
        // dashboard).
        function isDark() {
            return document.documentElement.getAttribute('data-theme') === 'dark';
        }
        function inkColor() { return isDark() ? '#c8ced6' : '#666'; }
        function gridColor() { return isDark() ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.1)'; }
        function countAxis() {
            return {
                ticks: { beginAtZero: true, precision: 0, fontColor: inkColor() },
                gridLines: { color: gridColor(), zeroLineColor: gridColor() }
            };
        }
        function labelAxis(stacked) {
            return {
                stacked: !!stacked,
                ticks: { fontColor: inkColor() },
                gridLines: { color: gridColor(), zeroLineColor: gridColor() }
            };
        }
        function legend() { return { labels: { fontColor: inkColor() } }; }

        var charts = [];

        // Portfolio cost share per lessor.
        charts.push(new Chart(document.getElementById('lessorCostChart'), {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{ backgroundColor: palette.slice(0, data.labels.length), borderWidth: 0, data: data.cost }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, legend: legend(),
                tooltips: { callbacks: { label: function (item, chartData) {
                    return chartData.labels[item.index] + ': ' + money(chartData.datasets[0].data[item.index]);
                } } }
            }
        }));

        // Fleet counts per lessor.
        charts.push(new Chart(document.getElementById('lessorDevicesChart'), {
            type: 'horizontalBar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: @json(trans('admin/purchase-orders/general.lease_assets')), backgroundColor: '#3c8dbc', data: data.assets },
                    { label: @json(trans('admin/purchase-orders/general.lease_active')), backgroundColor: '#00a65a', data: data.active },
                    { label: @json(trans('admin/purchase-orders/general.lease_buyouts')), backgroundColor: '#f39c12', data: data.buyout }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, legend: legend(),
                scales: { xAxes: [countAxis()], yAxes: [labelAxis(false)] }
            }
        }));

        // Ownership mix, stacked so each lessor's bar totals its fleet.
        charts.push(new Chart(document.getElementById('lessorOwnershipChart'), {
            type: 'horizontalBar',
            data: {
                labels: data.labels,
                datasets: data.ownership.map(function (series, i) {
                    return { label: series.label, backgroundColor: palette[i % palette.length], data: series.data };
                })
            },
            options: {
                responsive: true, maintainAspectRatio: false, legend: legend(),
                scales: {
                    xAxes: [Object.assign(countAxis(), { stacked: true })],
                    yAxes: [labelAxis(true)]
                }
            }
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
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    })();
</script>
@stop
