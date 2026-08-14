@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.waves_title') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('deployment-waves.create') }}" class="btn btn-sm btn-primary">
        <i class="fas fa-plus" aria-hidden="true"></i> {{ trans('admin/deployments/general.create') }}
    </a>
@stop

{{-- Every wave, every year, one list — the board shows one fiscal year at
     a time, so "which waves exist at all" had no answer. The two catalogs
     that shape waves (types and per-device stages) are managed right here,
     beside the things they describe, instead of behind a Configure page
     nobody could find. --}}
@section('content')

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

@can('deployments.edit')
<div class="row">
    {{-- Wave types: what kind of wave this is (refresh, rollout, …). --}}
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/deployments/general.catalog_types') }}</h3>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>{{ trans('general.name') }}</th>
                            <th style="width:70px;">{{ trans('admin/deployments/general.catalog_color') }}</th>
                            <th style="width:70px;">{{ trans('admin/deployments/general.catalog_sort') }}</th>
                            <th style="width:60px;">{{ trans('admin/deployments/general.catalog_active') }}</th>
                            <th style="width:120px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($types as $type)
                        <tr>
                            {{-- One form per row, spread across the cells via the
                                 form attribute so the row stays a plain <tr>. --}}
                            <td>
                                <form id="type-{{ $type->id }}" method="POST" action="{{ route('deployment-config.update', ['types', $type->id]) }}">
                                    {{ csrf_field() }}@method('PUT')
                                </form>
                                <input form="type-{{ $type->id }}" type="text" name="name" class="form-control input-sm" value="{{ $type->name }}">
                            </td>
                            <td><input form="type-{{ $type->id }}" type="color" name="color" class="form-control input-sm" value="{{ $type->color ?: '#2980b9' }}" style="padding:2px;"></td>
                            <td><input form="type-{{ $type->id }}" type="number" name="sort_order" class="form-control input-sm" value="{{ $type->sort_order }}"></td>
                            <td class="text-center"><input form="type-{{ $type->id }}" type="checkbox" name="active" value="1" @checked($type->active)></td>
                            <td class="text-right" style="white-space:nowrap;">
                                <button form="type-{{ $type->id }}" type="submit" class="btn btn-xs btn-default" title="{{ trans('general.save') }}"><i class="fas fa-check"></i></button>
                                <form method="POST" action="{{ route('deployment-config.destroy', ['types', $type->id]) }}" style="display:inline-block;"
                                      onsubmit="return confirm({{ json_encode(trans('general.sure_to_delete_var', ['item' => $type->name])) }});">
                                    {{ csrf_field() }}@method('DELETE')
                                    <button type="submit" class="btn btn-xs btn-danger" title="{{ trans('general.delete') }}"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                        <tr>
                            <td>
                                <form id="type-new" method="POST" action="{{ route('deployment-config.store', 'types') }}">
                                    {{ csrf_field() }}
                                </form>
                                <input form="type-new" type="text" name="name" class="form-control input-sm" placeholder="{{ trans('admin/deployments/general.catalog_new_type') }}">
                            </td>
                            <td><input form="type-new" type="color" name="color" class="form-control input-sm" value="#2980b9" style="padding:2px;"></td>
                            <td><input form="type-new" type="number" name="sort_order" class="form-control input-sm" value="0"></td>
                            <td class="text-center"><input form="type-new" type="checkbox" name="active" value="1" checked></td>
                            <td class="text-right">
                                <button form="type-new" type="submit" class="btn btn-xs btn-primary"><i class="fas fa-plus"></i> {{ trans('general.create') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Stages: the per-device pipeline, with the bridge to a Snipe status
         label for terminal stages. --}}
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/deployments/general.catalog_stages') }}</h3>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>{{ trans('general.name') }}</th>
                            <th style="width:70px;">{{ trans('admin/deployments/general.catalog_color') }}</th>
                            <th style="width:70px;">{{ trans('admin/deployments/general.catalog_sort') }}</th>
                            <th style="width:60px;">{{ trans('admin/deployments/general.catalog_terminal') }}</th>
                            <th>{{ trans('admin/deployments/general.catalog_maps_to_status') }}</th>
                            <th style="width:60px;">{{ trans('admin/deployments/general.catalog_active') }}</th>
                            <th style="width:120px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($stages as $stage)
                        <tr>
                            <td>
                                <form id="stage-{{ $stage->id }}" method="POST" action="{{ route('deployment-config.update', ['stages', $stage->id]) }}">
                                    {{ csrf_field() }}@method('PUT')
                                </form>
                                <input form="stage-{{ $stage->id }}" type="text" name="name" class="form-control input-sm" value="{{ $stage->name }}">
                            </td>
                            <td><input form="stage-{{ $stage->id }}" type="color" name="color" class="form-control input-sm" value="{{ $stage->color ?: '#2980b9' }}" style="padding:2px;"></td>
                            <td><input form="stage-{{ $stage->id }}" type="number" name="sort_order" class="form-control input-sm" value="{{ $stage->sort_order }}"></td>
                            <td class="text-center"><input form="stage-{{ $stage->id }}" type="checkbox" name="is_terminal" value="1" @checked($stage->is_terminal)></td>
                            <td>
                                <select form="stage-{{ $stage->id }}" name="maps_to_status_id" class="form-control input-sm">
                                    <option value="">—</option>
                                    @foreach ($statuslabels as $label)
                                        <option value="{{ $label->id }}" @selected((int) $stage->maps_to_status_id === (int) $label->id)>{{ $label->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="text-center"><input form="stage-{{ $stage->id }}" type="checkbox" name="active" value="1" @checked($stage->active)></td>
                            <td class="text-right" style="white-space:nowrap;">
                                <button form="stage-{{ $stage->id }}" type="submit" class="btn btn-xs btn-default" title="{{ trans('general.save') }}"><i class="fas fa-check"></i></button>
                                <form method="POST" action="{{ route('deployment-config.destroy', ['stages', $stage->id]) }}" style="display:inline-block;"
                                      onsubmit="return confirm({{ json_encode(trans('general.sure_to_delete_var', ['item' => $stage->name])) }});">
                                    {{ csrf_field() }}@method('DELETE')
                                    <button type="submit" class="btn btn-xs btn-danger" title="{{ trans('general.delete') }}"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                        <tr>
                            <td>
                                <form id="stage-new" method="POST" action="{{ route('deployment-config.store', 'stages') }}">
                                    {{ csrf_field() }}
                                </form>
                                <input form="stage-new" type="text" name="name" class="form-control input-sm" placeholder="{{ trans('admin/deployments/general.catalog_new_stage') }}">
                            </td>
                            <td><input form="stage-new" type="color" name="color" class="form-control input-sm" value="#2980b9" style="padding:2px;"></td>
                            <td><input form="stage-new" type="number" name="sort_order" class="form-control input-sm" value="0"></td>
                            <td class="text-center"><input form="stage-new" type="checkbox" name="is_terminal" value="1"></td>
                            <td>
                                <select form="stage-new" name="maps_to_status_id" class="form-control input-sm">
                                    <option value="">—</option>
                                    @foreach ($statuslabels as $label)
                                        <option value="{{ $label->id }}">{{ $label->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="text-center"><input form="stage-new" type="checkbox" name="active" value="1" checked></td>
                            <td class="text-right">
                                <button form="stage-new" type="submit" class="btn btn-xs btn-primary"><i class="fas fa-plus"></i> {{ trans('general.create') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endcan

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
