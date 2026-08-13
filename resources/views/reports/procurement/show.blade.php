@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ $reportTitle }}
    @parent
@stop

{{-- Page-header actions --}}
@section('header_right')
    @if (! empty($fyFilterable))
        {{-- Carries the dashboard's fiscal-year scope; preserves any other
             report params (mode, status, …) as hidden inputs so switching
             FY doesn't drop them. --}}
        <form method="get" style="display:inline-block; margin-right:4px;">
            @foreach (($reportParams ?? []) as $paramKey => $paramValue)
                @if (! in_array($paramKey, ['fiscal_year', 'format'], true))
                    <input type="hidden" name="{{ $paramKey }}" value="{{ $paramValue }}">
                @endif
            @endforeach
            <select name="fiscal_year" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
                <option value="all" {{ ($selectedFy ?? null) === null ? 'selected' : '' }}>{{ trans('admin/purchase-orders/general.all_fiscal_years') }}</option>
                @foreach (($allFiscalYears ?? collect()) as $fy)
                    <option value="{{ $fy }}" {{ ($selectedFy ?? null) === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                @endforeach
            </select>
        </form>
    @endif
    {!! $controls ?? '' !!}
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
@stop

{{-- Page content --}}
@section('content')

{{-- Reports that ship chart series get them as a row above the table —
     the picture first, the rows to chase it with underneath. --}}
@if (! empty($reportCharts))
<div class="row">
    @php($chartCols = max(3, (int) floor(12 / max(1, count($reportCharts)))))
    @foreach ($reportCharts as $chart)
        <div class="col-md-{{ $loop->first && count($reportCharts) === 3 ? 6 : $chartCols }} col-sm-6">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ $chart['title'] }}</h3>
                </div>
                <div class="box-body">
                    <div style="position:relative; height:240px;">
                        <canvas id="{{ $chart['id'] }}"></canvas>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-default rpt-report-box">
            <div class="box-body">
                @include('reports.procurement._report-table', [
                    'columns' => $columns,
                    'rows'    => $rows,
                    'footer'  => $footer ?? null,
                    'canEditNotes' => $canEditNotes ?? false,
                    'nowrapExceptLast' => $nowrapExceptLast ?? false,
                    'sortable' => $sortable ?? false,
                ])
            </div>
        </div>
    </div>
</div>
@include('reports.procurement._report-note-js')
@include('reports.procurement._report-children-js')
@include('reports.procurement._report-sticky-js')
@stop

@if (! empty($reportCharts))
@section('moar_scripts')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
(function () {
    if (typeof Chart === 'undefined') { return; }

    var specs = @json($reportCharts);
    var palette = ['#3c8dbc', '#f39c12', '#00a65a', '#dd4b39', '#39cccc', '#605ca8', '#00c0ef', '#d81b60'];
    var charts = [];

    var money = function (value) {
        return '$' + Number(value).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };

    function themeColors() {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            font: dark ? '#c8ced6' : '#666',
            grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.1)'
        };
    }

    function render() {
        var c = themeColors();
        charts.forEach(function (chart) { chart.destroy(); });
        charts = [];

        specs.forEach(function (spec) {
            var el = document.getElementById(spec.id);
            if (! el) { return; }

            var fmt = spec.money
                ? function (value) { return money(value); }
                : function (value) { return Number(value).toLocaleString('en-CA'); };

            if (spec.type === 'bar') {
                charts.push(new Chart(el, {
                    type: 'bar',
                    data: {
                        labels: spec.labels,
                        datasets: [{ data: spec.data, backgroundColor: '#3c8dbc' }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, legend: { display: false },
                        tooltips: { callbacks: { label: function (item) { return fmt(item.yLabel); } } },
                        scales: {
                            xAxes: [{ gridLines: { display: false }, ticks: { fontColor: c.font, autoSkip: false, maxRotation: 60 } }],
                            yAxes: [{ gridLines: { color: c.grid }, ticks: { beginAtZero: true, callback: fmt, fontColor: c.font } }]
                        }
                    }
                }));
                return;
            }

            charts.push(new Chart(el, {
                type: 'doughnut',
                data: {
                    labels: spec.labels,
                    datasets: [{ backgroundColor: palette.slice(0, spec.labels.length), borderWidth: 0, data: spec.data }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    legend: { labels: { fontColor: c.font } },
                    tooltips: { callbacks: { label: function (item, data) {
                        return data.labels[item.index] + ': ' + fmt(data.datasets[0].data[item.index]);
                    } } }
                }
            }));
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
@endif
