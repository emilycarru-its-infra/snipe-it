@extends('layouts/default')

@section('title')
    {{ trans('admin/custom_fields/general.field_groups') }} @parent
@stop

@section('header_right')
    <a href="{{ route('fields.index') }}" class="btn btn-sm btn-default">{{ trans('admin/custom_fields/general.custom_fields') }}</a>
    <a href="{{ route('field-groups.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> {{ trans('button.add') }}</a>
@stop

@section('content')

<p class="text-muted">{{ trans('admin/custom_fields/general.field_groups_about') }}</p>

<div class="box box-default">
    <div class="box-header with-border">
        <h2 class="box-title">{{ trans('admin/custom_fields/general.field_groups') }}</h2>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/custom_fields/general.field_group_name') }}</th>
                    <th>{{ trans('admin/custom_fields/general.field_group_color') }}</th>
                    <th>{{ trans('admin/custom_fields/general.field_group_sort') }}</th>
                    <th class="text-center">{{ trans('admin/custom_fields/general.field_group_collapsed') }}</th>
                    <th class="text-center">{{ trans('admin/custom_fields/general.qty_fields') }}</th>
                    <th class="text-center">{{ trans('admin/custom_fields/general.field_group_active') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($groups as $group)
                <tr class="{{ $group->active ? '' : 'text-muted' }}">
                    <td>
                        <span class="label" style="background-color: {{ $group->color ?: '#bdc3c7' }}; color:#fff;">
                            @if ($group->icon)<i class="{{ $group->icon }}"></i> @endif{{ $group->name }}
                        </span>
                    </td>
                    <td><code>{{ $group->color }}</code></td>
                    <td>{{ $group->sort_order }}</td>
                    <td class="text-center">@if ($group->collapsed_by_default)<i class="fas fa-check text-success"></i>@else <i class="fas fa-minus text-muted"></i>@endif</td>
                    <td class="text-center">{{ $group->fields_count }}</td>
                    <td class="text-center">@if ($group->active)<i class="fas fa-check text-success"></i>@else <i class="fas fa-times text-muted"></i>@endif</td>
                    <td class="text-right">
                        <a href="{{ route('field-groups.edit', $group->id) }}" class="btn btn-xs btn-warning"><i class="fas fa-pencil-alt"></i></a>
                        <form method="POST" action="{{ route('field-groups.destroy', $group->id) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/custom_fields/general.field_group_delete_confirm') }}');">
                            {{ csrf_field() }}@method('DELETE')
                            <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="box box-default">
    <div class="box-header with-border">
        <h2 class="box-title">{{ trans('admin/custom_fields/general.unassigned_fields') }}</h2>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('general.name') }}</th>
                    <th>{{ trans('admin/custom_fields/general.db_field') }}</th>
                    <th>{{ trans('admin/custom_fields/general.field_group') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($fields as $field)
                <tr>
                    <td>{{ $field->name }}</td>
                    <td><code>{{ $field->db_column }}</code></td>
                    <td>
                        <form method="POST" action="{{ route('field-groups.assign', $field->id) }}" class="form-inline">
                            {{ csrf_field() }}
                            <select name="field_group_id" class="form-control input-sm" onchange="this.form.submit()">
                                <option value="">{{ trans('admin/custom_fields/general.field_group_none') }}</option>
                                @foreach ($groups as $group)
                                    <option value="{{ $group->id }}" {{ $field->field_group_id == $group->id ? 'selected' : '' }}>{{ $group->name }}</option>
                                @endforeach
                            </select>
                            <noscript><button type="submit" class="btn btn-xs btn-primary">{{ trans('general.save') }}</button></noscript>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@stop
