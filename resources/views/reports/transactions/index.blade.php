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
        <h3 class="box-title">Transactions Reports</h3>
        <p class="box-subtitle" style="margin:6px 0 0; color:#aaa; font-size:12px;">
            View or download the reports that back the two final Reconcile tabs Finance receives.
        </p>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <tbody>
            @foreach ($reports as $r)
                <tr>
                    <td style="padding:12px 15px;">
                        <a href="{{ route($r['route'], ['year' => $current->period_year, 'month' => $current->period_month]) }}"
                           style="font-weight:600;">{{ $r['title'] }}</a>
                        <div style="color:#999; font-size:12px; margin-top:2px;">{{ $r['desc'] }}</div>
                    </td>
                    <td class="text-right" style="white-space:nowrap; padding:12px 15px;">
                        <a class="btn btn-sm btn-default"
                           href="{{ route($r['route'], ['year' => $current->period_year, 'month' => $current->period_month]) }}">
                            <i class="far fa-eye"></i> View
                        </a>
                        <a class="btn btn-sm btn-default"
                           href="{{ route($r['route'], ['year' => $current->period_year, 'month' => $current->period_month, 'format' => 'csv']) }}">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@endif

@stop

@section('moar_scripts')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
(function() {
    if (typeof Chart === 'undefined') { return; }

    const monthly = @json($monthly ?? []);
    const deptMix = @json($deptMix ?? []);

    const monthlyCtx = document.getElementById('transactionsMonthlyChart');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: monthly.map(m => m.label),
                datasets: [
                    { label: 'Self-Serve Revenue', data: monthly.map(m => m.revenue),
                      borderColor: '#1976d2', backgroundColor: 'rgba(25,118,210,0.15)', tension: 0.3, fill: true },
                    { label: 'DW Deposits',       data: monthly.map(m => m.deposits),
                      borderColor: '#43a047', backgroundColor: 'rgba(67,160,71,0.15)', tension: 0.3, fill: true },
                    { label: 'Refunds Posted',    data: monthly.map(m => m.refunds),
                      borderColor: '#fbc02d', backgroundColor: 'rgba(251,192,45,0.15)', tension: 0.3, fill: true },
                ],
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' } },
                scales: { y: { ticks: { callback: v => '$' + Number(v).toLocaleString() } } },
            },
        });
    }

    const deptCtx = document.getElementById('transactionsDeptChart');
    if (deptCtx) {
        new Chart(deptCtx, {
            type: 'doughnut',
            data: {
                labels: deptMix.map(d => d.label),
                datasets: [{
                    data: deptMix.map(d => d.value),
                    backgroundColor: [
                        '#1976d2', '#43a047', '#fbc02d', '#e53935', '#8e24aa',
                        '#00897b', '#fb8c00', '#3949ab', '#c0ca33', '#00acc1',
                        '#d81b60', '#5e35b1',
                    ],
                }],
            },
            options: {
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 10 } } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.label + ': $' + Number(ctx.parsed).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                        }
                    }
                },
            },
        });
    }
})();
</script>
@stop
