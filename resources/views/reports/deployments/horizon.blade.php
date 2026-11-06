@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.horizon_title') }} @parent
@stop

@section('content')

@php $money = fn (float $v) => ($v < 0 ? '(' : '').'$'.number_format(abs($v), 2).($v < 0 ? ')' : ''); @endphp

<p class="text-muted">{{ trans('admin/deployments/general.horizon_help') }}</p>

<div style="display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:15px;">
    <a href="{{ route('deployments.planning') }}" class="btn btn-default"><i class="fas fa-calendar-alt"></i> {{ trans('admin/deployments/general.forecast') }}</a>
    <a href="{{ route('deployment-waves.index') }}" class="btn btn-default"><i class="fas fa-water"></i> {{ trans('admin/deployments/general.waves_title') }}</a>
</div>

<div class="box box-default">
    <div class="box-body table-responsive">
        <table class="table table-striped planning-horizon">
            <thead>
                <tr>
                    <th></th>
                    @foreach ($columns as $col)
                        <th class="text-right" data-fy="{{ $col['fy'] }}">{{ $col['fy'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th>{{ trans('admin/deployments/general.horizon_devices') }}</th>
                    @foreach ($columns as $col)
                        <td class="text-right">
                            <strong>{{ $col['devices'] }}</strong>
                            <div class="text-muted" style="font-size:12px;">{{ trans('admin/deployments/general.horizon_devices_detail', ['forecast' => $col['forecast_devices'], 'waves' => $col['wave_devices']]) }}</div>
                        </td>
                    @endforeach
                </tr>
                @can('procurement.view')
                    <tr>
                        <th>{{ trans('admin/deployments/general.horizon_cost') }}</th>
                        @foreach ($columns as $col)
                            <td class="text-right">{{ $money($col['cost']) }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>{{ trans('admin/deployments/general.horizon_envelope') }}</th>
                        @foreach ($columns as $col)
                            <td class="text-right">{{ $money($col['envelope']) }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>{{ trans('admin/deployments/general.horizon_gap') }}</th>
                        @foreach ($columns as $col)
                            <td class="text-right {{ $col['gap'] < 0 ? 'text-danger' : 'text-success' }}">{{ $money($col['gap']) }}</td>
                        @endforeach
                    </tr>
                @endcan
                <tr>
                    <th>{{ trans('admin/deployments/general.horizon_waves') }}</th>
                    @foreach ($columns as $col)
                        <td class="text-right">
                            <strong>{{ $col['waves']->count() }}</strong>
                            @foreach ($col['waves'] as $wave)
                                <div style="font-size:12px;"><a href="{{ route('deployment-waves.show', $wave) }}">{{ $wave->name }}</a> <span class="text-muted">({{ $wave->items_count }})</span></div>
                            @endforeach
                        </td>
                    @endforeach
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th></th>
                    @foreach ($columns as $col)
                        <td class="text-right">
                            <a href="{{ route('deployments.planning', ['fiscal_year' => $col['fy']]) }}" class="btn btn-xs btn-default">{{ trans('admin/deployments/general.horizon_open_planning') }}</a>
                            @can('procurement.view')
                                <a href="{{ route('reports.procurement.capital-request', ['fiscal_year' => $col['fy']]) }}" class="btn btn-xs btn-default">{{ trans('admin/deployments/general.horizon_open_capital') }}</a>
                            @endcan
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>
</div>

@stop
