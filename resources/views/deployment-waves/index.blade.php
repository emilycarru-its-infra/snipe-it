@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.waves_title') }}
    @parent
@stop


{{-- Every wave, every year, one list — the board shows one fiscal year at
     a time, so "which waves exist at all" had no answer. The two catalogs
     that shape waves (types and per-device stages) are managed right here,
     beside the things they describe, instead of behind a Configure page
     nobody could find. --}}
@section('content')

@php
    $nwStart = now()->month >= 4 ? now()->year : now()->year - 1;
    $nwFy = sprintf('FY%d-%02d', $nwStart, ($nwStart + 1) % 100);
@endphp
{{-- Actions on the left, next to what they act on. --}}
<div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
    @can('deployments.edit')
        @include('reports.deployments._new-wave-popover', ['popoverId' => 'waves-new-wave', 'fy' => $nwFy, 'types' => $types])
    @endcan
</div>

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.waves_title') }} — {{ $waves->count() }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('general.name') }}</th>
                    <th>{{ trans('general.type') }}</th>
                    <th>{{ trans('admin/deployments/general.fiscal_year') }}</th>
                    <th>{{ trans('admin/deployments/general.wave_state') }}</th>
                    <th class="text-right">{{ trans('admin/deployments/general.device') }}(s)</th>
                    <th>{{ trans('admin/deployments/general.announced_at') }}</th>
                    <th>{{ trans('admin/deployments/general.arrival_window') }}</th>
                    <th>{{ trans('admin/deployments/general.owner') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($waves as $wave)
                <tr>
                    <td><a class="js-lightbox" href="{{ route('deployment-waves.show', $wave) }}">{{ $wave->name }}</a></td>
                    <td><span class="label" style="background-color: {{ $wave->displayColor() }}; color:#fff;">{{ $wave->typeLabel() }}</span></td>
                    <td>{{ $wave->fiscal_year ?: '—' }}</td>
                    <td>{{ ucfirst($wave->wave_state ?: '—') }}</td>
                    <td class="text-right">{{ $wave->items_count }}</td>
                    <td>{{ optional($wave->announced_at)->toDateString() ?: '—' }}</td>
                    <td>{{ optional($wave->arrival_window_start)->toDateString() ?: '?' }} – {{ optional($wave->arrival_window_end)->toDateString() ?: '?' }}</td>
                    <td>{{ $wave->owner?->full_name ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted">{{ trans('general.no_results') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Staff availability blackouts (vacation / OOO). Configuration for the
     timeline overlay, so it lives here with the other wave-shaping tables
     rather than on a page of its own. Graph-synced rows are read-only. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.blackouts_title') }}</h3>
        @can('deployments.edit')
            <div class="box-tools pull-right">
                <a href="{{ route('deployments.blackouts.create') }}" class="btn btn-xs btn-primary"><i class="fas fa-plus"></i> {{ trans('button.add') }}</a>
            </div>
        @endcan
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.blackout_staff') }}</th>
                    <th>{{ trans('admin/deployments/general.blackout_start') }}</th>
                    <th>{{ trans('admin/deployments/general.blackout_end') }}</th>
                    <th>{{ trans('admin/deployments/general.blackout_reason') }}</th>
                    <th>{{ trans('admin/deployments/general.blackout_source') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($blackouts as $blackout)
                <tr>
                    <td>{{ $blackout->user?->present()->fullName ?: trans('admin/deployments/general.blackout_unknown_user') }}</td>
                    <td>{{ optional($blackout->start_date)->toDateString() }}</td>
                    <td>{{ optional($blackout->end_date)->toDateString() }}</td>
                    <td>{{ $blackout->reason ?: '—' }}</td>
                    <td>
                        @if ($blackout->source === 'graph')
                            <span class="label label-info">{{ trans('admin/deployments/general.blackout_source_graph') }}</span>
                        @else
                            <span class="label label-default">{{ trans('admin/deployments/general.blackout_source_manual') }}</span>
                        @endif
                    </td>
                    <td class="text-right">
                        @if ($blackout->source === 'manual')
                            @can('deployments.edit')
                                <a href="{{ route('deployments.blackouts.edit', $blackout->id) }}" class="btn btn-xs btn-warning"><i class="fas fa-pencil-alt"></i></a>
                                <form method="POST" action="{{ route('deployments.blackouts.destroy', $blackout->id) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/deployments/general.blackout_delete_confirm') }}');">
                                    {{ csrf_field() }}@method('DELETE')
                                    <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            @endcan
                        @else
                            <span class="text-muted">{{ trans('admin/deployments/general.blackout_source_graph') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted">{{ trans('admin/deployments/general.blackout_none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@stop
