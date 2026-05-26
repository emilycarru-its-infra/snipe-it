@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/purchase-orders/general.dashboard_title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

<div class="row">
    <div class="col-md-12">
        <div class="box-header" style="padding-left:0;">
            <h1 class="box-title" style="font-size:22px; margin:0; display:inline-block; vertical-align:middle;">
                {{ trans('admin/purchase-orders/general.dashboard_title') }}
            </h1>
            <form method="get" style="display:inline-block; margin-left:15px; vertical-align:middle;">
                <select name="fiscal_year" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
                    <option value="">{{ trans('admin/purchase-orders/general.all_fiscal_years') }}</option>
                    @foreach ($allFiscalYears as $fy)
                        <option value="{{ $fy }}" {{ $selectedFy === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4 col-sm-6">
        @can('budget_allocations.manage')
            <a href="#" data-toggle="modal" data-target="#budgetAllocationsModal" class="small-box-link" style="text-decoration:none;">
        @endcan
            <div class="small-box bg-aqua">
                <div class="inner">
                    <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($totalBudget) }}</h3>
                    <p>
                        {{ trans('admin/purchase-orders/general.card_budget') }}
                        @can('budget_allocations.manage')
                            &middot; {{ $allocations->count() }} {{ trans('admin/budget-allocations/general.allocations') }}
                        @endcan
                    </p>
                </div>
                <div class="icon"><i class="fas fa-wallet" aria-hidden="true"></i></div>
            </div>
        @can('budget_allocations.manage')
            </a>
        @endcan
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-yellow">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($totalCommitted) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_committed') }}</p>
            </div>
            <div class="icon"><i class="fas fa-file-signature" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box {{ $totalRemaining < 0 ? 'bg-red' : 'bg-green' }}">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($totalRemaining) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_remaining') }}</p>
            </div>
            <div class="icon"><i class="fas fa-balance-scale" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-blue">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($totalInvoiced) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_invoiced') }} &middot; {{ $invoiceCount }} {{ trans('admin/orders/general.invoices') }}</p>
            </div>
            <div class="icon"><i class="fas fa-receipt" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-navy">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($plannedTotal) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_forecast') }}</p>
            </div>
            <div class="icon"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-purple">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($eolEstimate) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_eol', ['count' => $eolCount]) }}</p>
            </div>
            <div class="icon"><i class="fas fa-hourglass-end" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-teal">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($leaseExpiryTotal) }}</h3>
                <p>{!! trans(
                    $selectedFy
                        ? 'admin/purchase-orders/general.card_lease_preapproval'
                        : 'admin/purchase-orders/general.card_lease_preapproval_all',
                    ['count' => $leaseExpiryCount]
                ) !!}</p>
            </div>
            <div class="icon"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.procurement.invoice-approval') }}" class="small-box-link">
            <div class="small-box {{ $pendingApprovalCount > 0 ? 'bg-red' : 'bg-green' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ $pendingApprovalCount }}</h3>
                    <p>{{ trans('admin/purchase-orders/general.card_pending_approvals', ['count' => $pendingApprovalCount]) }}</p>
                </div>
                <div class="icon"><i class="fas fa-clipboard-check" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.procurement.lease-decisions', ['status' => 'pending']) }}" class="small-box-link">
            <div class="small-box {{ $pendingDecisionCount > 0 ? 'bg-yellow' : 'bg-aqua' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ $pendingDecisionCount }}</h3>
                    <p>{{ trans('admin/purchase-orders/general.card_pending_decisions', ['count' => $pendingDecisionCount]) }}</p>
                </div>
                <div class="icon"><i class="fas fa-handshake" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.procurement.faculty-ledger') }}" class="small-box-link">
            <div class="small-box {{ $facultyAwaitingSignatureCount > 0 ? 'bg-yellow' : 'bg-aqua' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ $facultyAwaitingSignatureCount }}</h3>
                    <p>{{ trans('admin/purchase-orders/general.card_faculty_unsigned', ['count' => $facultyAwaitingSignatureCount]) }}</p>
                </div>
                <div class="icon"><i class="fas fa-file-signature" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.procurement.schedule-signing') }}" class="small-box-link">
            <div class="small-box {{ $scheduleSigningQueueCount > 0 ? 'bg-yellow' : 'bg-aqua' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ $scheduleSigningQueueCount }}</h3>
                    <p>{{ trans('admin/purchase-orders/general.card_schedules_unsigned', ['count' => $scheduleSigningQueueCount]) }}</p>
                </div>
                <div class="icon"><i class="fas fa-stamp" aria-hidden="true"></i></div>
            </div>
        </a>
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

                @php $hiddenReports = (array) (auth()->user()?->hidden_procurement_reports ?? []); @endphp

                @if (! empty($hiddenReports))
                    <p>
                        <a href="#" id="show-all-procurement-reports">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            {{ trans('admin/purchase-orders/general.reports_hidden_count', ['count' => count($hiddenReports)]) }}
                        </a>
                    </p>
                @endif

                <table class="table table-striped">
                    <tbody>
                    @foreach (collect([
                        ['route' => 'reports.procurement.po-budget', 'name' => 'report_po_budget', 'desc' => 'report_po_budget_desc', 'live' => true],
                        ['route' => 'reports.procurement.invoices', 'name' => 'report_invoices', 'desc' => 'report_invoices_desc', 'live' => true],
                        ['route' => 'reports.procurement.capital', 'name' => 'report_capital', 'desc' => 'report_capital_desc', 'live' => true],
                        ['route' => 'reports.procurement.forecast', 'name' => 'report_forecast', 'desc' => 'report_forecast_desc', 'live' => true],
                        ['route' => 'reports.procurement.leases-operational', 'name' => 'report_leases_operational', 'desc' => 'report_leases_operational_desc', 'live' => true],
                        ['route' => 'reports.procurement.leases-financial', 'name' => 'report_leases_financial', 'desc' => 'report_leases_financial_desc', 'live' => true],
                        ['route' => 'reports.procurement.csi-schedule', 'name' => 'report_csi_schedule', 'desc' => 'report_csi_schedule_desc', 'live' => true],
                        ['route' => 'reports.procurement.invoice-approval', 'name' => 'report_invoice_approval', 'desc' => 'report_invoice_approval_desc', 'live' => true],
                        ['route' => 'reports.procurement.lease-decisions', 'name' => 'report_lease_decisions', 'desc' => 'report_lease_decisions_desc', 'live' => true],
                        ['route' => 'reports.procurement.po-disposition', 'name' => 'report_po_disposition', 'desc' => 'report_po_disposition_desc', 'live' => true],
                        ['route' => 'reports.procurement.extension-watch', 'name' => 'report_extension_watch', 'desc' => 'report_extension_watch_desc', 'live' => true],
                        ['route' => 'reports.procurement.aro-register', 'name' => 'report_aro_register', 'desc' => 'report_aro_register_desc', 'live' => true],
                        ['route' => 'reports.procurement.asset-lease-detail', 'name' => 'report_asset_lease_detail', 'desc' => 'report_asset_lease_detail_desc', 'live' => true],
                        ['route' => 'reports.procurement.po-drilldown', 'name' => 'report_po_drilldown', 'desc' => 'report_po_drilldown_desc', 'live' => true],
                        ['route' => 'reports.procurement.disposition-grid', 'name' => 'report_disposition_grid', 'desc' => 'report_disposition_grid_desc', 'live' => true],
                        ['route' => 'reports.procurement.credit-ledger', 'name' => 'report_credit_ledger', 'desc' => 'report_credit_ledger_desc', 'live' => true],
                        ['route' => 'reports.procurement.lessor-breakdown', 'name' => 'report_lessor_breakdown', 'desc' => 'report_lessor_breakdown_desc', 'live' => true],
                        ['route' => 'reports.procurement.pst-applicability', 'name' => 'report_pst_applicability', 'desc' => 'report_pst_applicability_desc', 'live' => true],
                        ['route' => 'reports.procurement.faculty-ledger', 'name' => 'report_faculty_ledger', 'desc' => 'report_faculty_ledger_desc', 'live' => true],
                        ['route' => 'reports.procurement.gl-transfer', 'name' => 'report_gl_transfer', 'desc' => 'report_gl_transfer_desc', 'live' => true],
                        ['route' => 'reports.procurement.schedule-signing', 'name' => 'report_schedule_signing', 'desc' => 'report_schedule_signing_desc', 'live' => true],
                    ])->reject(fn ($r) => in_array($r['name'], $hiddenReports, true)) as $report)
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
                                <a href="#" class="btn btn-sm btn-default text-muted hide-procurement-report"
                                   data-report="{{ $report['name'] }}"
                                   data-tooltip="true" title="{{ trans('admin/purchase-orders/general.report_hide') }}">
                                    <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
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

@can('budget_allocations.manage')
@include('reports.partials.budget-allocations-modal', [
    'allocations'        => $allocations,
    'selectedFy'         => $selectedFy,
    'allFiscalYears'     => $allFiscalYears,
    'budgetSourceLabels' => $budgetSourceLabels,
])
@endcan
@stop

@section('moar_scripts')
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
        var moneyAxis = function () { return { ticks: { beginAtZero: true, callback: money } }; };
        var barTooltip = { callbacks: { label: function (item, data) {
            return data.datasets[item.datasetIndex].label + ': ' + money(item.yLabel);
        } } };
        var pieTooltip = { callbacks: { label: function (item, data) {
            return data.labels[item.index] + ': ' + money(data.datasets[0].data[item.index]);
        } } };

        new Chart(document.getElementById('procPoChart'), {
            type: 'bar',
            data: {
                labels: data.poLabels,
                datasets: [
                    { label: @json(trans('admin/purchase-orders/general.card_budget')), backgroundColor: '#00c0ef', data: data.poBudget },
                    { label: @json(trans('admin/purchase-orders/general.card_committed')), backgroundColor: '#f39c12', data: data.poCommitted }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTooltip, scales: { yAxes: [moneyAxis()] } }
        });

        new Chart(document.getElementById('procUtilChart'), {
            type: 'doughnut',
            data: {
                labels: [@json(trans('admin/purchase-orders/general.card_committed')), @json(trans('admin/purchase-orders/general.card_remaining'))],
                datasets: [{ backgroundColor: ['#f39c12', '#00a65a'], data: [data.committed, data.remaining] }]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: pieTooltip }
        });

        new Chart(document.getElementById('procFyChart'), {
            type: 'bar',
            data: {
                labels: data.fyLabels,
                datasets: [
                    { label: @json(trans('admin/purchase-orders/general.card_committed')), backgroundColor: '#f39c12', data: data.fyCommitted },
                    { label: @json(trans('admin/purchase-orders/general.card_forecast')), backgroundColor: '#001f3f', data: data.fyPlanned },
                    { label: @json(trans('admin/purchase-orders/general.chart_lease_ending')), backgroundColor: '#39cccc', data: data.fyLeaseEnding }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTooltip, scales: { yAxes: [moneyAxis()] } }
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
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTooltip, scales: { yAxes: [moneyAxis()] } }
        });
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
</script>
@stop
