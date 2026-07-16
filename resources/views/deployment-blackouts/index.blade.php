@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.blackouts_title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('reports.deployments') }}" class="btn btn-sm btn-default">{{ trans('admin/deployments/general.dashboard_title') }}</a>
    <a href="{{ route('deployments.blackouts.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> {{ trans('button.add') }}</a>
@stop

@section('content')
<div class="box box-default">
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
                            <a href="{{ route('deployments.blackouts.edit', $blackout->id) }}" class="btn btn-xs btn-warning"><i class="fas fa-pencil-alt"></i></a>
                            <form method="POST" action="{{ route('deployments.blackouts.destroy', $blackout->id) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/deployments/general.blackout_delete_confirm') }}');">
                                {{ csrf_field() }}@method('DELETE')
                                <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
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
