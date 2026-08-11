@extends('layouts/default')

@section('title')
    {{ trans('admin/reports/general.fleet_health') }} @parent
@stop

@section('header_right')
    <a href="{{ route('reports.index') }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.reports') }}
    </a>
@stop

@section('content')

{{-- ── Headline KPI cards ─────────────────────────────────────────── --}}
<div class="row">
    @foreach ($cards as $card)
        <div class="col-md-3 col-sm-6">
            <div class="small-box bg-{{ $card['tone'] }}">
                <div class="inner">
                    <h3>{{ number_format($card['value']) }}</h3>
                    <p>{{ $card['label'] }}</p>
                </div>
                <div class="icon"><i class="fas {{ $card['icon'] }}" aria-hidden="true"></i></div>
            </div>
        </div>
    @endforeach
</div>

{{-- ── Status donut + Top models ─────────────────────────────────── --}}
<div class="row">
    <div class="col-md-5">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/reports/general.fleet_status_chart_title') }}</h3>
                <span class="text-muted small">{{ trans('admin/reports/general.fleet_status_chart_help') }}</span>
            </div>
            <div class="box-body">
                <canvas id="statusDonut" height="240"></canvas>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/reports/general.fleet_top_models_title') }}</h3>
                <span class="text-muted small">{{ trans('admin/reports/general.fleet_top_models_help') }}</span>
            </div>
            <div class="box-body">
                <canvas id="topModels" height="240"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- ── Age histogram + Top repairs ────────────────────────────────── --}}
<div class="row">
    <div class="col-md-5">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/reports/general.fleet_age_chart_title') }}</h3>
                <span class="text-muted small">{{ trans('admin/reports/general.fleet_age_chart_help') }}</span>
            </div>
            <div class="box-body">
                <canvas id="ageHistogram" height="220"></canvas>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/reports/general.fleet_top_repairs_title') }}</h3>
                <span class="text-muted small">{{ trans('admin/reports/general.fleet_top_repairs_help') }}</span>
            </div>
            <div class="box-body">
                <canvas id="topRepairs" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- ── Unfunded fleet (Legacy + Buyouts) ──────────────────────────── --}}
@if (($legacyFleet['count'] ?? 0) > 0)
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fas fa-hourglass-half" aria-hidden="true"></i>
                    {{ trans('admin/deployments/general.legacy_box_title') }}
                    <span class="text-muted" style="font-weight:normal;">· {{ $legacyFleet['count'] }} · ${{ \App\Helpers\Helper::formatCurrencyOutput($legacyFleet['purchase_cost'] ?? 0) }}</span>
                </h3>
            </div>
            <div class="box-body">
                <p class="text-muted" style="margin:0 0 10px;">{{ trans('admin/deployments/general.legacy_note') }}</p>
                <div class="row">
                    @foreach ($legacyFleet['sections'] as $section)
                        <div class="col-md-6">
                            <a href="{{ url('hardware') }}?status_id={{ $section['status_ids'][0] }}" style="font-weight:700;">
                                {{ $section['label'] }} · {{ $section['count'] }} · ${{ \App\Helpers\Helper::formatCurrencyOutput($section['purchase_cost']) }}
                            </a>
                            <span class="text-muted" style="font-size:12px; margin-left:6px;">
                                {{ trans('admin/deployments/general.legacy_age_note', [
                                    'age' => $section['avg_age_years'] ?? '—',
                                    'oldest' => $section['oldest_year'] ?? '—',
                                ]) }}
                            </span>
                            <div style="margin-top:6px;">
                                @foreach ($section['by_model'] as $legacyRow)
                                    <a href="{{ url('hardware') }}?status_id={{ $section['status_ids'][0] }}&model_id={{ $legacyRow['model_id'] }}"
                                       class="label label-default" style="display:inline-block; margin:0 4px 4px 0;">
                                        {{ $legacyRow['model'] }} · {{ $legacyRow['count'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Audit-overdue callout ──────────────────────────────────────── --}}
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/reports/general.fleet_audit_card_title') }}</h3>
            </div>
            <div class="box-body">
                @if ($auditOverdue === 0)
                    <p class="text-muted" style="margin:0;">
                        {{ trans('admin/reports/general.fleet_audit_zero') }}
                    </p>
                @else
                    <p style="margin:0; font-size:18px;">
                        <strong>{{ number_format($auditOverdue) }}</strong>
                        {{ trans('admin/reports/general.fleet_audit_overdue', ['count' => $auditOverdue]) }}
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>

@stop

@section('inline-scripts')
<script src="{{ mix('js/dist/Chart.min.js') }}"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') { return; }

    var statusData = @json($statusDonut);
    var topModelsData = @json($topModels);
    var ageData = @json($ageHistogram);
    var topRepairsData = @json($topRepairs);

    function mountDonut(id, rows) {
        var ctx = document.getElementById(id);
        if (! ctx) return;
        new Chart(ctx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: rows.map(function (r) { return r.label; }),
                datasets: [{
                    data: rows.map(function (r) { return r.count; }),
                    backgroundColor: rows.map(function (r) { return r.color || '#3c8dbc'; }),
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { position: 'right' },
            },
        });
    }

    function mountHBar(id, rows, color) {
        var ctx = document.getElementById(id);
        if (! ctx) return;
        new Chart(ctx.getContext('2d'), {
            type: 'horizontalBar',
            data: {
                labels: rows.map(function (r) { return r.label; }),
                datasets: [{
                    data: rows.map(function (r) { return r.count; }),
                    backgroundColor: color,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: false },
                scales: { xAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] },
            },
        });
    }

    function mountVBar(id, rows, color) {
        var ctx = document.getElementById(id);
        if (! ctx) return;
        new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: rows.map(function (r) { return r.label; }),
                datasets: [{
                    data: rows.map(function (r) { return r.count; }),
                    backgroundColor: color,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: false },
                scales: { yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] },
            },
        });
    }

    mountDonut('statusDonut', statusData);
    mountHBar('topModels', topModelsData, '#3c8dbc');
    mountVBar('ageHistogram', ageData, '#00a65a');
    mountHBar('topRepairs', topRepairsData, '#dd4b39');
})();
</script>
@stop
