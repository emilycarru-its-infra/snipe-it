@extends('layouts/default')

@section('title')
    {{ trans('admin/reports/transactions.dashboard_title') }}
    @parent
@stop

@section('content')

@if ($current)
    <div class="row">
        <div class="col-md-12" style="margin-bottom:15px; color:#888;">
            {{ trans('admin/reports/transactions.col_period') }}:
            <strong>{{ $current->period_label }}</strong>
            &middot;
            {{ trans('admin/reports/transactions.col_generated') }}:
            {{ $current->generated_at?->diffForHumans() ?? '—' }}
        </div>
    </div>
@endif

@if (! $current)
    <div class="alert alert-info">
        {{ trans('admin/reports/transactions.empty_reconciliations') }}
    </div>
@else

{{-- ── Status cards ─────────────────────────────────────────────────── --}}
<div class="row">
    @foreach ($cards as $card)
        <div class="col-md-4 col-sm-6">
            <div class="small-box bg-{{ $card['tone'] }}">
                <div class="inner">
                    <h3 style="font-size:24px">
                        @if ($card['fmt'] === 'money')
                            ${{ number_format($card['value'], 2) }}
                        @else
                            {{ number_format($card['value']) }}
                        @endif
                    </h3>
                    <p>{{ $card['label'] }}</p>
                </div>
                <div class="icon"><i class="fas {{ $card['icon'] }}" aria-hidden="true"></i></div>
            </div>
        </div>
    @endforeach
</div>

{{-- ── Charts row ───────────────────────────────────────────────────── --}}
<div class="row">
    <div class="col-md-8">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Monthly Revenue, Deposits & Refunds</h3>
            </div>
            <div class="box-body">
                <canvas id="transactionsMonthlyChart" height="120"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Revenue by Department — {{ $current->period_label }}</h3>
            </div>
            <div class="box-body">
                <canvas id="transactionsDeptChart" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- ── Reports menu ─────────────────────────────────────────────────── --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">Reconciliations history</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Generated</th>
                    <th>Status</th>
                    <th class="text-right">Workbook</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($latest as $r)
                <tr>
                    <td><a href="{{ route('reports.transactions.show', ['ym' => $r->period_label]) }}">{{ $r->period_label }}</a></td>
                    <td>{{ $r->generated_at?->diffForHumans() ?? '—' }}</td>
                    <td><span class="label label-{{ $r->status === 'published' ? 'success' : 'warning' }}">{{ $r->status }}</span></td>
                    <td class="text-right">
                        @if ($r->sharepoint_url)
                            <a class="btn btn-xs btn-default" href="{{ $r->sharepoint_url }}" target="_blank" rel="noopener">
                                <i class="far fa-file-excel"></i> Open in SharePoint
                            </a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- ── Drill-down widgets ───────────────────────────────────────────── --}}
<div class="row">
    {{-- GL Breakdown --}}
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">GL Breakdown <small style="color:#999;">— top 8 by dollar total (calendar period)</small></h3>
                <div class="box-tools pull-right">
                    <a class="btn btn-xs btn-default"
                       href="{{ route('reports.transactions.gl-breakdown', ['year' => $current->period_year, 'month' => $current->period_month]) }}">
                        View all <i class="fas fa-angle-right"></i>
                    </a>
                </div>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <thead>
                        <tr><th>GL code</th><th class="text-right">Dollars</th><th class="text-right">Fee share</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($widgets['gl'] ?? [] as $g)
                        <tr>
                            <td>{{ $g->gl_code }}</td>
                            <td class="text-right">${{ number_format((float) $g->dollar_total, 2) }}</td>
                            <td class="text-right">${{ number_format((float) $g->fee_share, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center" style="padding:18px; color:#aaa;">No GL rows for this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Mail Room Allocation --}}
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Mail Room Allocation <small style="color:#999;">— most recent 8 jobs</small></h3>
                <div class="box-tools pull-right">
                    <a class="btn btn-xs btn-default"
                       href="{{ route('reports.transactions.mail-room', ['year' => $current->period_year, 'month' => $current->period_month]) }}">
                        View all <i class="fas fa-angle-right"></i>
                    </a>
                </div>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <thead>
                        <tr><th>User</th><th>Department</th><th>Document</th><th class="text-right">Pages</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($widgets['mailroom'] ?? [] as $m)
                        <tr>
                            <td>{{ $m->row_data['full name'] ?? $m->row_data['username'] ?? '—' }}</td>
                            <td>{{ $m->row_data['user department'] ?? $m->row_data['mapped_department'] ?? '—' }}</td>
                            <td style="max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $m->row_data['document'] ?? '—' }}</td>
                            <td class="text-right">{{ $m->row_data['total printed pages'] ?? $m->row_data['copies'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center" style="padding:18px; color:#aaa;">No mail-room jobs for this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row">
    {{-- Refunds Posted --}}
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Transactions Summary <small style="color:#999;">— PaperCut totals by type</small></h3>
                <div class="box-tools pull-right">
                    <a class="btn btn-xs btn-default"
                       href="{{ route('reports.transactions.refunds', ['year' => $current->period_year, 'month' => $current->period_month]) }}">
                        View all <i class="fas fa-angle-right"></i>
                    </a>
                </div>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <thead>
                        <tr><th>Type</th><th class="text-right">Count</th><th class="text-right">Net</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($widgets['refunds'] ?? [] as $r)
                        @php
                            $type = $r->row_data['transaction type'] ?? '—';
                            $debits = (int) ($r->row_data['no. of debits'] ?? 0);
                            $credits = (int) ($r->row_data['no. of credits'] ?? 0);
                            $count = $debits + $credits;
                            // PaperCut prefixes negative numbers with a stray apostrophe; strip it.
                            $net = (float) ltrim((string) ($r->row_data['net'] ?? '0'), "'");
                            $isRefund = str_contains(strtoupper($type), 'REFUND');
                        @endphp
                        <tr>
                            <td>{{ $type }}{!! $isRefund ? ' <span class="label label-success">refund</span>' : '' !!}</td>
                            <td class="text-right">{{ number_format($count) }}</td>
                            <td class="text-right">${{ number_format($net, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center" style="padding:18px; color:#aaa;">No transactions for this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Self-Serve Print breakdown --}}
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Self-Serve Print — per-department JT</h3>
                <p class="box-subtitle" style="margin:6px 0 0; color:#aaa; font-size:12px;">
                    The single Finance posts to Colleague each month.
                </p>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover">
                    <thead>
                        <tr><th>Department</th><th class="text-right">Amount</th></tr>
                    </thead>
                    <tbody>
                    @php $jtTotal = 0; @endphp
                    @forelse ($widgets['selfServe'] ?? [] as $row)
                        @php
                            $label = ucwords(str_replace(['revenue_', '_'], ['', ' '], $row->line_key));
                            $jtTotal += (float) $row->amount;
                        @endphp
                        <tr>
                            <td>{{ $label }}</td>
                            <td class="text-right">${{ number_format((float) $row->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-center" style="padding:18px; color:#aaa;">No per-department revenue rows.</td></tr>
                    @endforelse
                    </tbody>
                    @if (! empty($widgets['selfServe']) && count($widgets['selfServe']) > 0)
                    <tfoot>
                        <tr><th>Total</th><th class="text-right">${{ number_format($jtTotal, 2) }}</th></tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ── Settings / admin links (not really "reports") ─────────────────── --}}
@canany(['reports.transactions.overrides'])
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fas fa-cog"></i> Reconciliation settings</h3>
    </div>
    <div class="box-body" style="padding:12px 15px;">
        <a class="btn btn-sm btn-default"
           href="{{ route('reports.transactions.overrides', ['year' => $current->period_year, 'month' => $current->period_month]) }}">
            <i class="fas fa-pen-to-square"></i> Manual overrides
        </a>
        <a class="btn btn-sm btn-default"
           href="{{ route('reports.transactions.line-items', ['year' => $current->period_year, 'month' => $current->period_month]) }}">
            <i class="fas fa-list"></i> Effective line items
        </a>
        <span style="color:#aaa; font-size:12px; margin-left:8px;">
            Per-line corrections (overrides win over derived) and the flat-table view of every Reconcile-tab cell.
        </span>
    </div>
</div>
@endcanany

@endif

@stop

@section('moar_scripts')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
(function() {
    if (typeof Chart === 'undefined') { return; }

    // Snipe-IT ships Chart.js v2.9 — uses tooltips (not plugins.tooltip),
    // yAxes (not scales.y), and legacy callback signatures. Configuring
    // for v3+ silently no-ops, which is how an unformatted "Tool Checkout:
    // -3743.77" tooltip slipped through.
    var money = function (v) {
        var n = Number(v);
        var abs = Math.abs(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return (n < 0 ? '-$' : '$') + abs;
    };

    var monthly = @json($monthly ?? []);
    var deptMix = @json($deptMix ?? []);

    var monthlyCtx = document.getElementById('transactionsMonthlyChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: monthly.map(function (m) { return m.label; }),
                datasets: [
                    { label: 'Self-Serve Revenue', data: monthly.map(function (m) { return m.revenue; }),
                      borderColor: '#1976d2', backgroundColor: 'rgba(25,118,210,0.15)', lineTension: 0.3, fill: true },
                    { label: 'DW Deposits',       data: monthly.map(function (m) { return m.deposits; }),
                      borderColor: '#43a047', backgroundColor: 'rgba(67,160,71,0.15)', lineTension: 0.3, fill: true },
                    { label: 'Refunds Posted',    data: monthly.map(function (m) { return m.refunds; }),
                      borderColor: '#fbc02d', backgroundColor: 'rgba(251,192,45,0.15)', lineTension: 0.3, fill: true },
                ],
            },
            options: {
                responsive: true,
                legend: { position: 'top' },
                tooltips: {
                    callbacks: {
                        label: function (item, data) {
                            var ds = data.datasets[item.datasetIndex].label || '';
                            return ds + ': ' + money(item.yLabel);
                        }
                    }
                },
                scales: {
                    yAxes: [{
                        ticks: { callback: function (v) { return money(v); } }
                    }]
                },
            },
        });
    }

    var deptCtx = document.getElementById('transactionsDeptChart');
    if (deptCtx) {
        new Chart(deptCtx, {
            type: 'doughnut',
            data: {
                labels: deptMix.map(function (d) { return d.label; }),
                datasets: [{
                    data: deptMix.map(function (d) { return d.value; }),
                    backgroundColor: [
                        '#1976d2', '#43a047', '#fbc02d', '#e53935', '#8e24aa',
                        '#00897b', '#fb8c00', '#3949ab', '#c0ca33', '#00acc1',
                        '#d81b60', '#5e35b1',
                    ],
                }],
            },
            options: {
                legend: { position: 'bottom', labels: { fontSize: 10 } },
                tooltips: {
                    callbacks: {
                        label: function (item, data) {
                            var label = data.labels[item.index];
                            var value = data.datasets[0].data[item.index];
                            return label + ': ' + money(value);
                        }
                    }
                },
            },
        });
    }
})();
</script>
@stop
