@extends('layouts/default')

@section('title')
    {{ trans('admin/contracts/general.dashboard_title') }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-12">
        <div class="box-header" style="padding-left:0;">
            <h1 class="box-title" style="font-size:22px; margin:0; display:inline-block; vertical-align:middle;">
                {{ trans('admin/contracts/general.dashboard_title') }}
            </h1>
            <form method="get" style="display:inline-block; margin-left:15px; vertical-align:middle;">
                <select name="fiscal_year" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
                    <option value="all" {{ $selectedFy === null ? 'selected' : '' }}>{{ trans('admin/contracts/general.all_fiscal_years') }}</option>
                    @foreach ($allFiscalYears as $fy)
                        <option value="{{ $fy }}" {{ $selectedFy === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>
</div>

{{-- KPI strip --}}
<div class="row">
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-aqua">
            <div class="inner">
                <h3 style="font-size:24px">{{ number_format($activeCount) }}</h3>
                <p>{{ trans('admin/contracts/general.card_active') }}</p>
            </div>
            <div class="icon"><i class="fas fa-file-contract" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-blue">
            <div class="inner">
                <h3 style="font-size:24px">${{ \App\Helpers\Helper::formatCurrencyOutput($totalCost) }}</h3>
                <p>{{ trans('admin/contracts/general.card_total_spend') }}</p>
            </div>
            <div class="icon"><i class="fas fa-wallet" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.contracts.expiring-soon', ['days' => 30]) }}" class="small-box-link">
            <div class="small-box {{ $expiring30 > 0 ? 'bg-red' : 'bg-green' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ number_format($expiring30) }}</h3>
                    <p>{{ trans('admin/contracts/general.card_expiring_30') }}</p>
                </div>
                <div class="icon"><i class="fas fa-hourglass-end" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.contracts.expiring-soon', ['days' => 90]) }}" class="small-box-link">
            <div class="small-box {{ $expiring90 > 0 ? 'bg-yellow' : 'bg-aqua' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ number_format($expiring90) }}</h3>
                    <p>{{ trans('admin/contracts/general.card_expiring_90') }}</p>
                </div>
                <div class="icon"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.contracts.umbrellas') }}" class="small-box-link">
            <div class="small-box bg-navy">
                <div class="inner">
                    <h3 style="font-size:24px">{{ number_format($umbrellaCount) }}</h3>
                    <p>{{ trans('admin/contracts/general.card_umbrellas') }}</p>
                </div>
                <div class="icon"><i class="fas fa-sitemap" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.contracts.serial-register') }}" class="small-box-link">
            <div class="small-box bg-purple">
                <div class="inner">
                    <h3 style="font-size:24px">{{ number_format($serialRegister) }}</h3>
                    <p>{{ trans('admin/contracts/general.card_serial_register') }}</p>
                </div>
                <div class="icon"><i class="fas fa-barcode" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/contracts/general.chart_spend_by_fy') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:300px;">
                    <canvas id="contractsFyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/contracts/general.chart_provider') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:300px;">
                    <canvas id="contractsProviderChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/contracts/general.chart_theme') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:280px;">
                    <canvas id="contractsThemeChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/contracts/general.chart_renewals') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:280px;">
                    <canvas id="contractsRenewalChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/contracts/general.reports') }}</h3>
            </div>
            <div class="box-body">
                <p class="text-muted">{{ trans('admin/contracts/general.reports_intro') }}</p>
                <table class="table table-striped">
                    <tbody>
                    @foreach ([
                        ['route' => 'reports.contracts.expiring-soon',     'name' => 'report_expiring_soon_title',   'desc' => 'report_expiring_soon_desc',   'params' => ['days' => 90]],
                        ['route' => 'reports.contracts.umbrellas',         'name' => 'report_umbrellas_title',       'desc' => 'report_umbrellas_desc',       'params' => []],
                        ['route' => 'reports.contracts.by-theme',          'name' => 'report_by_theme_title',        'desc' => 'report_by_theme_desc',        'params' => []],
                        ['route' => 'reports.contracts.by-provider',       'name' => 'report_by_provider_title',     'desc' => 'report_by_provider_desc',     'params' => []],
                        ['route' => 'reports.contracts.serial-register',   'name' => 'report_serial_register_title', 'desc' => 'report_serial_register_desc', 'params' => []],
                        ['route' => 'reports.contracts.naming-violators',  'name' => 'report_naming_violators_title','desc' => 'report_naming_violators_desc','params' => []],
                        ['route' => 'reports.contracts.stale',             'name' => 'report_stale_title',           'desc' => 'report_stale_desc',           'params' => []],
                    ] as $report)
                        <tr>
                            <td>
                                <strong><a href="{{ route($report['route'], $report['params']) }}">{{ trans('admin/contracts/general.'.$report['name']) }}</a></strong>
                                <br>
                                <span class="text-muted">{{ trans('admin/contracts/general.'.$report['desc']) }}</span>
                            </td>
                            <td class="text-right" style="vertical-align:middle; white-space:nowrap">
                                <a href="{{ route($report['route'], $report['params']) }}" class="btn btn-sm btn-primary">
                                    <x-icon type="reports" /> {{ trans('general.view') }}
                                </a>
                                <a href="{{ route($report['route'], array_merge($report['params'], ['format' => 'csv'])) }}" class="btn btn-sm btn-default">
                                    <x-icon type="download" /> {{ trans('general.download') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
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

        var data = {!! json_encode([
            'fyLabels'         => $spendByFy->keys()->all(),
            'fyValues'         => array_values($spendByFy->all()),
            'providerLabels'   => $spendByProvider->keys()->all(),
            'providerValues'   => array_values($spendByProvider->all()),
            'themeLabels'      => $countByTheme->keys()->all(),
            'themeValues'      => array_values($countByTheme->all()),
            'renewalLabels'    => $renewalCalendar->keys()->all(),
            'renewalValues'    => array_values($renewalCalendar->all()),
        ]) !!};

        var money = function (v) {
            return '$' + Number(v).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        };
        var moneyAxis = function () { return { ticks: { beginAtZero: true, callback: money } }; };
        var barTip = { callbacks: { label: function (i, d) { return d.datasets[i.datasetIndex].label + ': ' + money(i.yLabel); } } };
        var pieTip = { callbacks: { label: function (i, d) { return d.labels[i.index] + ': ' + money(d.datasets[0].data[i.index]); } } };

        new Chart(document.getElementById('contractsFyChart'), {
            type: 'bar',
            data: {
                labels: data.fyLabels,
                datasets: [
                    { label: @json(trans('admin/contracts/general.total_cost')), backgroundColor: '#3c8dbc', data: data.fyValues }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTip, scales: { yAxes: [moneyAxis()] } }
        });

        new Chart(document.getElementById('contractsProviderChart'), {
            type: 'doughnut',
            data: {
                labels: data.providerLabels,
                datasets: [{
                    backgroundColor: ['#00c0ef', '#f39c12', '#00a65a', '#dd4b39', '#605ca8', '#39cccc', '#ff851b', '#d81b60', '#3c8dbc', '#001f3f'],
                    data: data.providerValues
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: pieTip }
        });

        new Chart(document.getElementById('contractsThemeChart'), {
            type: 'horizontalBar',
            data: {
                labels: data.themeLabels,
                datasets: [{ label: @json(trans('general.count')), backgroundColor: '#00a65a', data: data.themeValues }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { xAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] } }
        });

        new Chart(document.getElementById('contractsRenewalChart'), {
            type: 'line',
            data: {
                labels: data.renewalLabels,
                datasets: [{
                    label: @json(trans('admin/contracts/general.chart_renewals')),
                    borderColor: '#dd4b39',
                    backgroundColor: 'rgba(221,75,57,0.15)',
                    data: data.renewalValues
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] } }
        });
    })();
</script>
@stop
