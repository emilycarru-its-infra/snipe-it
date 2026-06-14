@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.dashboard_title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('deployment-config.index', 'types') }}" class="btn btn-sm btn-default"><i class="fas fa-cog"></i> {{ trans('admin/deployments/general.configure') }}</a>
    <a href="{{ route('deployments.forecast', ['fiscal_year' => $fy]) }}" class="btn btn-sm btn-default"><i class="fas fa-calendar-alt"></i> {{ trans('admin/deployments/general.forecast') }}</a>
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default"><i class="fas fa-download"></i> {{ trans('admin/deployments/general.download') }}</a>
    <a href="{{ route('deployment-waves.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> {{ trans('admin/deployments/general.add_wave') }}</a>
@stop

@section('content')

{{-- Filters --}}
<div class="row">
    <div class="col-md-12">
        <form method="GET" action="{{ route('reports.deployments') }}" class="form-inline" style="margin-bottom:15px;">
            <div class="form-group">
                <label>{{ trans('admin/deployments/general.filter_fiscal_year') }}</label>
                <select name="fiscal_year" class="form-control" onchange="this.form.submit()">
                    @foreach ($fiscalYears as $y)
                        <option value="{{ $y }}" {{ (string) $fy === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>{{ trans('admin/deployments/general.filter_type') }}</label>
                <select name="deployment_type" class="form-control" onchange="this.form.submit()">
                    <option value="">{{ trans('admin/deployments/general.all_types') }}</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}" {{ (string) $typeFilter === (string) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>{{ trans('admin/deployments/general.filter_stage') }}</label>
                <select name="stage" class="form-control" onchange="this.form.submit()">
                    <option value="">{{ trans('admin/deployments/general.all_stages') }}</option>
                    @foreach ($stages as $st)
                        <option value="{{ $st->id }}" {{ (string) $stageFilter === (string) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
</div>

{{-- Forecast summary callout --}}
@if ($forecastCount > 0)
<div class="row">
    <div class="col-md-12">
        <div class="callout callout-info" style="margin-bottom:15px;">
            <i class="fas fa-calendar-alt"></i>
            {{ trans('admin/deployments/general.forecast_summary', ['count' => $forecastCount, 'fy' => $fy]) }}
            — <a href="{{ route('deployments.forecast', ['fiscal_year' => $fy]) }}">{{ trans('admin/deployments/general.add_from_forecast') }}</a>
        </div>
    </div>
</div>
@endif

{{-- Donut + count widgets --}}
<div class="row">
    @php($cards = [
        ['key' => 'stage', 'title' => trans('admin/deployments/general.widget_stage'), 'canvas' => 'deployStageChart'],
        ['key' => 'type', 'title' => trans('admin/deployments/general.widget_type'), 'canvas' => 'deployTypeChart'],
        ['key' => 'model', 'title' => trans('admin/deployments/general.widget_model'), 'canvas' => 'deployModelChart'],
    ])
    @foreach ($cards as $card)
        <div class="col-md-4">
            <div class="box box-default">
                <div class="box-header with-border"><h3 class="box-title">{{ $card['title'] }}</h3></div>
                <div class="box-body">
                    <div style="position:relative; height:200px; margin-bottom:10px;">
                        <canvas id="{{ $card['canvas'] }}"></canvas>
                    </div>
                    <table class="table table-striped" style="margin-bottom:0;">
                        <tbody>
                        @forelse ($widgets[$card['key']]['rows'] as $r)
                            <tr>
                                <td><span class="label" style="background-color: {{ $r['color'] }}; color:#fff;">{{ $r['label'] }}</span></td>
                                <td class="text-right"><strong>{{ $r['count'] }}</strong></td>
                                <td class="text-right text-muted">{{ $r['pct'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">—</td></tr>
                        @endforelse
                        </tbody>
                        <tfoot><tr><th>{{ trans('admin/deployments/general.total') }}</th><th class="text-right">{{ $widgets['total'] }}</th><th></th></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Waves table --}}
<div class="box box-default">
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.name') }}</th>
                    <th>{{ trans('admin/deployments/general.deployment_type') }}</th>
                    <th>{{ trans('admin/deployments/general.wave_state') }}</th>
                    <th>{{ trans('admin/deployments/general.fiscal_year') }}</th>
                    <th class="text-right">{{ trans('admin/deployments/general.device') }}s</th>
                    <th>{{ trans('admin/deployments/general.arrival_window') }}</th>
                    <th>{{ trans('admin/deployments/general.deploy_window') }}</th>
                    <th>{{ trans('admin/deployments/general.owner') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($waves as $wave)
                <tr>
                    <td><a href="{{ route('deployment-waves.show', $wave) }}"><span class="label" style="background-color: {{ $wave->displayColor() }}; color:#fff;">{{ $wave->name }}</span></a></td>
                    <td>{{ $wave->typeLabel() }}</td>
                    <td>{{ ucfirst($wave->wave_state) }}</td>
                    <td>{{ $wave->fiscal_year ?: '—' }}</td>
                    <td class="text-right">{{ $wave->items_count }}</td>
                    <td>
                        @if ($wave->arrival_window_start || $wave->arrival_window_end)
                            {{ optional($wave->arrival_window_start)->toDateString() ?: '?' }} – {{ optional($wave->arrival_window_end)->toDateString() ?: '?' }}
                        @else — @endif
                    </td>
                    <td>
                        @if ($wave->target_start_date || $wave->target_end_date)
                            {{ optional($wave->target_start_date)->toDateString() ?: '?' }} – {{ optional($wave->target_end_date)->toDateString() ?: '?' }}
                        @else — @endif
                    </td>
                    <td>{{ $wave->owner?->full_name ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted">{{ trans('admin/deployments/general.no_waves') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
(function () {
    var donut = function (id, payload) {
        var el = document.getElementById(id);
        if (!el || !payload.labels.length) { return; }
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: payload.labels,
                datasets: [{ data: payload.data, backgroundColor: payload.colors }]
            },
            options: { responsive: true, maintainAspectRatio: false, legend: { position: 'right' } }
        });
    };
    donut('deployStageChart', @json($widgets['stage']['chart']));
    donut('deployTypeChart', @json($widgets['type']['chart']));
    donut('deployModelChart', @json($widgets['model']['chart']));
})();
</script>
@stop
