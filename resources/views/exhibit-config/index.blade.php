@extends('layouts/default')

@section('title')
    {{ trans('admin/exhibit-projects/general.'.$labelKey) }} @parent
@stop

@section('header_right')
    <a href="{{ route('reports.exhibit') }}" class="btn btn-sm btn-default">{{ trans('admin/exhibit-projects/general.dashboard_title') }}</a>
    <a href="{{ route('exhibit-config.create', $catalog) }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> {{ trans('button.add') }}</a>
@stop

@section('content')
<ul class="nav nav-tabs" style="margin-bottom:15px;">
    @foreach (['exhibits' => 'catalog_exhibits', 'project-types' => 'catalog_project_types', 'statuses' => 'catalog_statuses'] as $key => $lbl)
        <li class="{{ $catalog === $key ? 'active' : '' }}"><a href="{{ route('exhibit-config.index', $key) }}">{{ trans('admin/exhibit-projects/general.'.$lbl) }}</a></li>
    @endforeach
</ul>

<div class="box box-default">
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/exhibit-projects/general.catalog_name') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.catalog_color') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.catalog_sort') }}</th>
                    <th>{{ trans('admin/exhibit-projects/general.catalog_active') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($items as $item)
                <tr class="{{ $item->active ? '' : 'text-muted' }}">
                    <td><span class="label" style="background-color: {{ $item->color ?: '#bdc3c7' }}; color:#fff;">{{ $item->name }}</span></td>
                    <td><code>{{ $item->color }}</code></td>
                    <td>{{ $item->sort_order }}</td>
                    <td>@if ($item->active)<i class="fas fa-check text-success"></i>@else <i class="fas fa-times text-muted"></i> @endif</td>
                    <td class="text-right">
                        <a href="{{ route('exhibit-config.edit', [$catalog, $item->id]) }}" class="btn btn-xs btn-warning"><i class="fas fa-pencil-alt"></i></a>
                        <form method="POST" action="{{ route('exhibit-config.destroy', [$catalog, $item->id]) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/exhibit-projects/general.catalog_delete_confirm') }}');">
                            {{ csrf_field() }}@method('DELETE')
                            <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@stop
