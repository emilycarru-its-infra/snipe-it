@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.'.$labelKey) }} @parent
@stop

@section('header_right')
    <a href="{{ route('reports.deployments') }}" class="btn btn-sm btn-default">{{ trans('admin/deployments/general.dashboard_title') }}</a>
    <a href="{{ route('deployment-config.create', $catalog) }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> {{ trans('button.add') }}</a>
@stop

@section('content')
<ul class="nav nav-tabs" style="margin-bottom:15px;">
    @foreach (['types' => 'catalog_types', 'stages' => 'catalog_stages'] as $key => $lbl)
        <li class="{{ $catalog === $key ? 'active' : '' }}"><a href="{{ route('deployment-config.index', $key) }}">{{ trans('admin/deployments/general.'.$lbl) }}</a></li>
    @endforeach
</ul>

<div class="box box-default">
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.catalog_name') }}</th>
                    <th>{{ trans('admin/deployments/general.catalog_color') }}</th>
                    @if ($catalog === 'stages')
                        <th>{{ trans('admin/deployments/general.catalog_terminal') }}</th>
                        <th>{{ trans('admin/deployments/general.catalog_maps_to_status') }}</th>
                    @endif
                    <th>{{ trans('admin/deployments/general.catalog_sort') }}</th>
                    <th>{{ trans('admin/deployments/general.catalog_active') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($items as $item)
                <tr class="{{ $item->active ? '' : 'text-muted' }}">
                    <td><span class="label" style="background-color: {{ $item->color ?: '#bdc3c7' }}; color:#fff;">{{ $item->name }}</span></td>
                    <td><code>{{ $item->color }}</code></td>
                    @if ($catalog === 'stages')
                        <td>@if ($item->is_terminal)<i class="fas fa-check text-success"></i>@else <i class="fas fa-times text-muted"></i> @endif</td>
                        <td>{{ $item->statusLabel?->name ?: '—' }}</td>
                    @endif
                    <td>{{ $item->sort_order }}</td>
                    <td>@if ($item->active)<i class="fas fa-check text-success"></i>@else <i class="fas fa-times text-muted"></i> @endif</td>
                    <td class="text-right">
                        <a href="{{ route('deployment-config.edit', [$catalog, $item->id]) }}" class="btn btn-xs btn-warning"><i class="fas fa-pencil-alt"></i></a>
                        <form method="POST" action="{{ route('deployment-config.destroy', [$catalog, $item->id]) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/deployments/general.catalog_delete_confirm') }}');">
                            {{ csrf_field() }}@method('DELETE')
                            <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $catalog === 'stages' ? 7 : 5 }}" class="text-center text-muted">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@stop
