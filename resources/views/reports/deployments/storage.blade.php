@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.storage_title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('reports.deployments') }}" class="btn btn-sm btn-default"><i class="fas fa-arrow-left"></i> {{ trans('admin/deployments/general.dashboard_title') }}</a>
@stop

@section('content')

@php($allRows = array_merge($rows, $unassignedCount > 0 ? [$unassigned] : []))

@if (count($rows) === 0 && $unassignedCount === 0)
    <div class="callout callout-info">
        <i class="fas fa-info-circle"></i> {{ trans('admin/deployments/general.storage_no_locations') }}
    </div>
@endif

<div class="row">
    @foreach ($allRows as $row)
        <div class="col-md-6">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">
                        <i class="fas fa-warehouse text-muted"></i>
                        @if ($row['location'])
                            <a href="{{ route('locations.show', $row['location']) }}">{{ $row['name'] }}</a>
                        @else
                            {{ $row['name'] }}
                        @endif
                    </h3>
                    <div class="box-tools pull-right">
                        @if ($row['capacity'])
                            <span class="label {{ $row['over'] > 0 ? 'label-danger' : 'label-default' }}">
                                {{ $row['count'] }} / {{ $row['capacity'] }}
                            </span>
                        @else
                            <span class="label label-default">{{ $row['count'] }} {{ trans('admin/deployments/general.storage_staged') }}</span>
                        @endif
                    </div>
                </div>
                <div class="box-body">
                    @if ($row['capacity'])
                        <div class="progress" style="margin-bottom:6px;">
                            <div class="progress-bar {{ $row['tone'] }}" role="progressbar"
                                 style="width: {{ $row['pct'] }}%; min-width:2em;"
                                 aria-valuenow="{{ $row['count'] }}" aria-valuemin="0" aria-valuemax="{{ $row['capacity'] }}">
                                {{ $row['pct'] }}%
                            </div>
                        </div>
                        @if ($row['over'] > 0)
                            <p class="text-danger" style="margin-bottom:6px;"><i class="fas fa-exclamation-triangle"></i> {{ trans('admin/deployments/general.storage_over_capacity', ['count' => $row['over']]) }}</p>
                        @endif
                    @else
                        <p class="text-muted" style="margin-bottom:6px;">{{ trans('admin/deployments/general.storage_uncapped') }}</p>
                    @endif

                    {{-- Waves staging here --}}
                    @if ($row['location'] && $wavesByStorage->has($row['location']->id))
                        <p style="margin-bottom:4px;"><strong>{{ trans('admin/deployments/general.storage_waves_here') }}</strong></p>
                        <p>
                            @foreach ($wavesByStorage->get($row['location']->id) as $w)
                                <a href="{{ route('deployment-waves.show', $w) }}"><span class="label" style="background-color: {{ $w->displayColor() }}; color:#fff;">{{ $w->name }}</span></a>
                            @endforeach
                        </p>
                    @endif

                    {{-- Device list --}}
                    <table class="table table-condensed table-striped" style="margin-bottom:0;">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/deployments/general.device') }}</th>
                                <th>{{ trans('admin/deployments/general.wave') }}</th>
                                <th>{{ trans('admin/deployments/general.stage') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($row['items'] as $item)
                            <tr>
                                <td>
                                    @if ($item->asset)
                                        <a href="{{ route('hardware.show', $item->asset) }}">{{ $item->deviceLabel() }}</a>
                                    @else
                                        {{ $item->deviceLabel() }}
                                    @endif
                                </td>
                                <td>@if ($item->wave)<a href="{{ route('deployment-waves.show', $item->wave) }}">{{ $item->wave->name }}</a>@else — @endif</td>
                                <td><span class="label" style="background-color: {{ $item->stageColor() }}; color:#fff;">{{ $item->stageLabel() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">{{ trans('admin/deployments/general.storage_no_devices') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>

@stop
