@extends('layouts/default')

@section('title')
    {{ trans('admin/purchase-orders/general.leasing_title') }}
    @parent
@stop

{{-- The lease portfolio, one page: what we lease and from whom (charts +
     lessor breakdown), and what a year of it costs contract by contract
     (Rent Costs). The charts lived on the reports hub before, a page most
     leasing questions never start from. --}}
@section('content')

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
                    'columns' => $breakdown['columns'],
                    'rows'    => $breakdown['records'],
                    'footer'  => $breakdown['footer'] ?? null,
                    'canEditNotes' => false,
                ])
            </div>
        </div>
    </div>
</div>

{{-- Rent Costs — the year's leasing bill, contract by contract. The
     Annual Rent bars above ARE the year picker: click a bar, get that
     year's breakdown here. --}}
<div class="row">
    <div class="col-md-12">
        <div class="box box-default rpt-report-box" id="rent-costs" style="scroll-margin-top:80px;">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.report_rent_costs') }} — {{ $rentCosts['fy'] }}</h3>
                <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ trans('admin/purchase-orders/general.rent_costs_bars_hint') }}</span>
                <div class="box-tools pull-right">
                    <a href="{{ route('reports.procurement.rent-costs', ['format' => 'csv', 'fiscal_year' => $rentCosts['fy']]) }}" class="btn btn-sm btn-default">
                        <x-icon type="download" /> {{ trans('general.download') }}
                    </a>
                </div>
            </div>
            <div class="box-body">
                {{-- Sortable: the register rests in contract-name order, and
                     every column is one click away — "which is biggest" and
                     "which ends first" are both routine follow-ups. --}}
                @include('reports.procurement._report-table', [
                    'columns' => $rentCosts['columns'],
                    'rows'    => $rentCosts['records'],
                    'footer'  => $rentCosts['footer'] ?? null,
                    'canEditNotes' => false,
                    'sortable' => $rentCosts['sortable'] ?? false,
                ])
            </div>
        </div>
    </div>
</div>

{{-- Lease Data Health — last on the page, because it is housekeeping
     rather than a finding: leases with no contract on file, end dates that
     will not parse, archived devices still reading as on-lease. It sits
     with the portfolio it describes rather than in the procurement
     stream, where it was more granular than anything around it. --}}
<div class="row">
    <div class="col-md-12">
        <div class="box box-default rpt-report-box" id="lease-data-health" style="scroll-margin-top:80px;">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.report_lease_data_health') }}</h3>
                <div class="box-tools pull-right">
                    <a href="{{ route('reports.procurement.lease-data-health', ['format' => 'csv']) }}" class="btn btn-sm btn-default">
                        <x-icon type="download" /> {{ trans('general.download') }}
                    </a>
                </div>
                <p class="text-muted" style="margin:8px 0 0; font-size:12.5px;">{{ trans('admin/purchase-orders/general.report_lease_data_health_desc') }}</p>
            </div>
            <div class="box-body">
                @include('reports.procurement._report-table', [
                    'columns' => $dataHealth['columns'],
                    'rows'    => $dataHealth['records'],
                    'footer'  => $dataHealth['footer'] ?? null,
                    'canEditNotes' => false,
                ])
            </div>
        </div>
    </div>
</div>

@stop

@section('moar_scripts')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
(function () {
    if (typeof Chart === 'undefined') { return; }

    var lessorChart = @json($breakdown['chart']);
    var lessorPalette = ['#3c8dbc', '#f39c12', '#00a65a', '#dd4b39', '#39cccc', '#605ca8', '#00c0ef', '#d81b60'];
    var charts = {};

    var money = function (value) {
        return '$' + Number(value).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };

    function themeColors() {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            font: dark ? '#c8ced6' : '#666',
            grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.1)',
            bar: '#3c8dbc'
        };
    }

    function render() {
        var c = themeColors();
        Object.keys(charts).forEach(function (key) { charts[key].destroy(); });

        // The bars are the Rent Costs year picker: clicking one reloads the
        // page scoped to that fiscal year, landing on the table. The bar
        // for the year on display is tinted to say which year the table
        // below is answering for.
        var selectedFy = @json($rentCosts['fy']);
        charts.annualRent = new Chart(document.getElementById('chart-lessor-annual-rent'), {
            type: 'bar',
            data: {
                labels: lessorChart.annualRent.labels,
                datasets: [{
                    data: lessorChart.annualRent.data,
                    backgroundColor: lessorChart.annualRent.labels.map(function (label) {
                        return label === selectedFy ? '#f39c12' : c.bar;
                    })
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, legend: { display: false },
                tooltips: { callbacks: { label: function (item) { return money(item.yLabel); } } },
                onClick: function (event, elements) {
                    if (! elements.length) { return; }
                    var fy = lessorChart.annualRent.labels[elements[0]._index];
                    window.location = '{{ route('reports.lessor-breakdown') }}?fiscal_year=' + encodeURIComponent(fy) + '#rent-costs';
                },
                hover: { onHover: function (event, elements) {
                    event.target.style.cursor = elements.length ? 'pointer' : 'default';
                } },
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

    render();

    new MutationObserver(render).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
    });
})();
</script>
@stop
