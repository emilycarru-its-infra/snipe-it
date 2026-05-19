@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/purchase-orders/general.dashboard_title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

<div class="row">
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-aqua">
            <div class="inner">
                <h3 style="font-size:24px">{{ Helper::formatCurrencyOutput($totalBudget) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_budget') }}</p>
            </div>
            <div class="icon"><i class="fas fa-wallet" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-yellow">
            <div class="inner">
                <h3 style="font-size:24px">{{ Helper::formatCurrencyOutput($totalCommitted) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_committed') }}</p>
            </div>
            <div class="icon"><i class="fas fa-file-signature" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box {{ $totalRemaining < 0 ? 'bg-red' : 'bg-green' }}">
            <div class="inner">
                <h3 style="font-size:24px">{{ Helper::formatCurrencyOutput($totalRemaining) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_remaining') }}</p>
            </div>
            <div class="icon"><i class="fas fa-balance-scale" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-blue">
            <div class="inner">
                <h3 style="font-size:24px">{{ Helper::formatCurrencyOutput($totalInvoiced) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_invoiced') }} &middot; {{ $invoiceCount }} {{ trans('admin/orders/general.invoices') }}</p>
            </div>
            <div class="icon"><i class="fas fa-receipt" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-navy">
            <div class="inner">
                <h3 style="font-size:24px">{{ Helper::formatCurrencyOutput($plannedTotal) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_forecast') }}</p>
            </div>
            <div class="icon"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-purple">
            <div class="inner">
                <h3 style="font-size:24px">{{ Helper::formatCurrencyOutput($eolEstimate) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_eol', ['count' => $eolCount]) }}</p>
            </div>
            <div class="icon"><i class="fas fa-hourglass-end" aria-hidden="true"></i></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_po') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:300px;">
                    <canvas id="procPoChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_utilization') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:300px;">
                    <canvas id="procUtilChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_fiscal_year') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:280px;">
                    <canvas id="procFyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_monthly') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:280px;">
                    <canvas id="procMonthlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.reports') }}</h3>
            </div>
            <div class="box-body">
                <p class="text-muted">{{ trans('admin/purchase-orders/general.reports_intro') }}</p>
                <table class="table table-striped">
                    <tbody>
                    @foreach ([
                        ['route' => 'reports.procurement.po-budget', 'name' => 'report_po_budget', 'desc' => 'report_po_budget_desc', 'live' => true],
                        ['route' => 'reports.procurement.invoices', 'name' => 'report_invoices', 'desc' => 'report_invoices_desc', 'live' => true],
                        ['route' => 'reports.procurement.capital', 'name' => 'report_capital', 'desc' => 'report_capital_desc', 'live' => true],
                        ['route' => 'reports.procurement.forecast', 'name' => 'report_forecast', 'desc' => 'report_forecast_desc', 'live' => true],
                        ['route' => 'reports.procurement.receiving', 'name' => 'report_receiving', 'desc' => 'report_receiving_desc', 'live' => false],
                        ['route' => 'reports.procurement.tax', 'name' => 'report_tax', 'desc' => 'report_tax_desc', 'live' => false],
                    ] as $report)
                        <tr>
                            <td>
                                @if ($report['live'])
                                    <strong><a href="{{ route($report['route']) }}">{{ trans('admin/purchase-orders/general.'.$report['name']) }}</a></strong>
                                @else
                                    <strong>{{ trans('admin/purchase-orders/general.'.$report['name']) }}</strong>
                                @endif
                                <br>
                                <span class="text-muted">{{ trans('admin/purchase-orders/general.'.$report['desc']) }}</span>
                            </td>
                            <td class="text-right" style="vertical-align:middle; white-space:nowrap">
                                @if ($report['live'])
                                    <a href="{{ route($report['route']) }}" class="btn btn-sm btn-primary">
                                        <x-icon type="reports" /> {{ trans('general.view') }}
                                    </a>
                                @endif
                                <a href="{{ route($report['route'], ['format' => 'csv']) }}" class="btn btn-sm btn-default">
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
            'monthlyLabels' => $monthlyLabels,
            'monthlyValues' => $monthlyValues,
        ]) !!};

        var money = function (value) {
            return '$' + Number(value).toLocaleString('en-CA', {minimumFractionDigits: 0, maximumFractionDigits: 0});
        };
        var moneyAxis = function () { return { ticks: { beginAtZero: true, callback: money } }; };

        new Chart(document.getElementById('procPoChart'), {
            type: 'bar',
            data: {
                labels: data.poLabels,
                datasets: [
                    { label: @json(trans('admin/purchase-orders/general.card_budget')), backgroundColor: '#00c0ef', data: data.poBudget },
                    { label: @json(trans('admin/purchase-orders/general.card_committed')), backgroundColor: '#f39c12', data: data.poCommitted }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { yAxes: [moneyAxis()] } }
        });

        new Chart(document.getElementById('procUtilChart'), {
            type: 'doughnut',
            data: {
                labels: [@json(trans('admin/purchase-orders/general.card_committed')), @json(trans('admin/purchase-orders/general.card_remaining'))],
                datasets: [{ backgroundColor: ['#f39c12', '#00a65a'], data: [data.committed, data.remaining] }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });

        new Chart(document.getElementById('procFyChart'), {
            type: 'bar',
            data: {
                labels: data.fyLabels,
                datasets: [
                    { label: @json(trans('admin/purchase-orders/general.card_committed')), backgroundColor: '#f39c12', data: data.fyCommitted },
                    { label: @json(trans('admin/purchase-orders/general.card_forecast')), backgroundColor: '#001f3f', data: data.fyPlanned }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { yAxes: [moneyAxis()] } }
        });

        new Chart(document.getElementById('procMonthlyChart'), {
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
            options: { responsive: true, maintainAspectRatio: false, scales: { yAxes: [moneyAxis()] } }
        });
    })();
</script>
@stop
