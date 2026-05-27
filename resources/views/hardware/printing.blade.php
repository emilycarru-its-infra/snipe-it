{{-- ── Per-asset Printing usage tab ──────────────────────────────────────────
     Rendered only when the asset's model fieldset is a printer fieldset
     (see PrinterUsageService::assetIsPrinter). Data structure passed via
     the `$usage` array comes from PrinterUsageService::summary.
     Roadmap: docs/printer-roadmap.md §2.3
--}}
@php
    $last30 = $usage['last30'];
    $monthly = $usage['monthly'];
    $latest = $usage['latestPeriod'];
    $topUsers = $usage['topUsers'];
    $recentJobs = $usage['recentJobs'];
    $glAllocation = $usage['glAllocation'];

    $periodLabel = $latest
        ? \Carbon\Carbon::create($latest['year'], $latest['month'], 1)->format('M Y')
        : null;
    // $monthly is always 12 entries (one per trailing month, zeros included),
    // so its presence isn't a signal -- only its contents are. Sum job
    // counts across the series instead.
    $monthlyJobs = collect($monthly)->sum('jobs');
    $hasAnyData = $last30['jobs'] > 0 || $monthlyJobs > 0 || $recentJobs->isNotEmpty();
@endphp

<div class="clearfix visible-lg-block" style="padding: 6px;"></div>

@if (! $hasAnyData)
    <div class="col-md-12">
        <div class="alert alert-info" style="margin-top:14px;">
            <strong>{{ trans('admin/hardware/printing.no_data') }}</strong>
            <p style="margin:6px 0 0;">{{ trans('admin/hardware/printing.no_data_hint') }}</p>
        </div>
    </div>
@else

{{-- ── Last-30-days totals ─────────────────────────────────────────── --}}
<div class="col-md-12">
    <div class="row">
        @php
            $cards = [
                ['label' => trans('admin/hardware/printing.jobs'),        'value' => number_format($last30['jobs']),                 'tone' => 'aqua',   'icon' => 'fa-print'],
                ['label' => trans('admin/hardware/printing.pages'),       'value' => number_format($last30['pages']),                'tone' => 'green',  'icon' => 'fa-file-alt'],
                ['label' => trans('admin/hardware/printing.cost'),        'value' => '$'.number_format($last30['cost'], 2),          'tone' => 'blue',   'icon' => 'fa-dollar-sign'],
                ['label' => trans('admin/hardware/printing.refund_rate'), 'value' => number_format($last30['refundRate'] * 100, 1).'%', 'tone' => $last30['refundRate'] > 0.1 ? 'red' : 'navy', 'icon' => 'fa-undo'],
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="col-md-3 col-sm-6">
                <div class="small-box bg-{{ $card['tone'] }}">
                    <div class="inner">
                        <h3 style="font-size:24px">{{ $card['value'] }}</h3>
                        <p>{{ $card['label'] }} <small style="opacity:0.75;">— {{ trans('admin/hardware/printing.last_30_days') }}</small></p>
                    </div>
                    <div class="icon"><i class="fas {{ $card['icon'] }}" aria-hidden="true"></i></div>
                </div>
            </div>
        @endforeach
    </div>
</div>

{{-- ── Monthly volume chart ────────────────────────────────────────── --}}
<div class="col-md-8">
    <div class="box box-default">
        <div class="box-header with-border">
            <h3 class="box-title">{{ trans('admin/hardware/printing.monthly_volume') }}</h3>
        </div>
        <div class="box-body">
            <canvas id="printerMonthlyChart-{{ $asset->id }}" height="160"></canvas>
        </div>
    </div>
</div>

{{-- ── GL allocation (collapses to a card when only one GL) ──────────── --}}
<div class="col-md-4">
    <div class="box box-default">
        <div class="box-header with-border">
            <h3 class="box-title">{{ trans('admin/hardware/printing.gl_allocation') }}</h3>
            @if ($periodLabel)
                <small style="color:#999;">{{ trans('admin/hardware/printing.gl_allocation_subtitle', ['period' => $periodLabel]) }}</small>
            @endif
        </div>
        <div class="box-body">
            @if ($glAllocation->isEmpty())
                <p class="text-muted text-center" style="padding:24px 0;">—</p>
            @elseif ($glAllocation->count() === 1)
                @php $only = $glAllocation->first(); @endphp
                <div class="text-center" style="padding:18px 0;">
                    <p style="font-size:18px; margin:0;"><strong>{{ $only['gl'] }}</strong></p>
                    <p class="text-muted" style="margin:6px 0 0;">${{ number_format($only['cost'], 2) }}</p>
                </div>
            @else
                <canvas id="printerGlChart-{{ $asset->id }}" height="200"></canvas>
            @endif
        </div>
    </div>
</div>

{{-- ── Top users + Recent jobs ─────────────────────────────────────── --}}
<div class="col-md-5">
    <div class="box box-default">
        <div class="box-header with-border">
            <h3 class="box-title">{{ trans('admin/hardware/printing.top_users') }}</h3>
            @if ($periodLabel)
                <small style="color:#999;">{{ trans('admin/hardware/printing.top_users_subtitle', ['period' => $periodLabel]) }}</small>
            @endif
        </div>
        <div class="box-body table-responsive no-padding">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>{{ trans('admin/hardware/printing.col_user') }}</th>
                        <th class="text-right">{{ trans('admin/hardware/printing.col_pages') }}</th>
                        <th class="text-right">{{ trans('admin/hardware/printing.jobs') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($topUsers as $row)
                    <tr>
                        <td>{{ $row['user'] }}</td>
                        <td class="text-right">{{ number_format($row['pages']) }}</td>
                        <td class="text-right">{{ number_format($row['jobs']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center" style="padding:14px; color:#aaa;">—</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="col-md-7">
    <div class="box box-default">
        <div class="box-header with-border">
            <h3 class="box-title">{{ trans('admin/hardware/printing.recent_jobs') }}</h3>
        </div>
        <div class="box-body table-responsive no-padding">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>{{ trans('admin/hardware/printing.col_when') }}</th>
                        <th>{{ trans('admin/hardware/printing.col_user') }}</th>
                        <th>{{ trans('admin/hardware/printing.col_document') }}</th>
                        <th class="text-right">{{ trans('admin/hardware/printing.col_pages') }}</th>
                        <th class="text-right">{{ trans('admin/hardware/printing.col_cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($recentJobs as $job)
                    <tr>
                        <td title="{{ $job['when']?->toDateTimeString() }}">{{ $job['when']?->diffForHumans() ?? '—' }}</td>
                        <td>{{ $job['user'] }}</td>
                        <td style="max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            {{ $job['document'] }}
                            @if ($job['isRefund'])
                                <span class="label label-success">{{ trans('admin/hardware/printing.refund_badge') }}</span>
                            @endif
                            @if ($job['mailroom'])
                                <span class="label label-default">{{ trans('admin/hardware/printing.mailroom') }}</span>
                            @endif
                        </td>
                        <td class="text-right">{{ number_format($job['pages']) }}</td>
                        <td class="text-right">${{ number_format($job['cost'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center" style="padding:14px; color:#aaa;">—</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('js')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
(function () {
    if (typeof Chart === 'undefined') { return; }

    var money = function (v) {
        var n = Number(v);
        var abs = Math.abs(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return (n < 0 ? '-$' : '$') + abs;
    };

    var monthly = @json($monthly);
    var monthlyCanvas = document.getElementById('printerMonthlyChart-{{ $asset->id }}');
    if (monthlyCanvas) {
        new Chart(monthlyCanvas, {
            type: 'bar',
            data: {
                labels: monthly.map(function (m) { return m.label; }),
                datasets: [
                    { label: '{{ trans('admin/hardware/printing.pages') }}',
                      data: monthly.map(function (m) { return m.pages; }),
                      backgroundColor: 'rgba(67,160,71,0.7)', yAxisID: 'y-pages' },
                    { label: '{{ trans('admin/hardware/printing.jobs') }}',
                      data: monthly.map(function (m) { return m.jobs; }),
                      backgroundColor: 'rgba(25,118,210,0.7)', yAxisID: 'y-jobs' },
                ],
            },
            options: {
                responsive: true,
                legend: { position: 'top' },
                tooltips: {
                    callbacks: {
                        label: function (item, data) {
                            var ds = data.datasets[item.datasetIndex].label || '';
                            return ds + ': ' + Number(item.yLabel).toLocaleString();
                        }
                    }
                },
                scales: {
                    yAxes: [
                        { id: 'y-pages', position: 'left',  ticks: { beginAtZero: true } },
                        { id: 'y-jobs',  position: 'right', ticks: { beginAtZero: true }, gridLines: { drawOnChartArea: false } },
                    ]
                },
            },
        });
    }

    @if ($glAllocation->count() > 1)
    var glData = @json($glAllocation->values());
    var glCanvas = document.getElementById('printerGlChart-{{ $asset->id }}');
    if (glCanvas) {
        new Chart(glCanvas, {
            type: 'doughnut',
            data: {
                labels: glData.map(function (g) { return g.gl; }),
                datasets: [{
                    data: glData.map(function (g) { return g.cost; }),
                    backgroundColor: [
                        '#1976d2', '#43a047', '#fbc02d', '#e53935', '#8e24aa',
                        '#00897b', '#fb8c00', '#3949ab', '#c0ca33', '#00acc1',
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
    @endif
})();
</script>
@endpush

@endif
