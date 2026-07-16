@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.'.$labelKey) }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-8 col-md-offset-2">
        <form class="form-horizontal" method="POST" action="{{ $item->exists ? route('deployment-config.update', [$catalog, $item->id]) : route('deployment-config.store', $catalog) }}">
            {{ csrf_field() }}
            @if ($item->exists) @method('PUT') @endif
            <div class="box box-default">
                <div class="box-body">
                    <div class="form-group {{ $errors->has('name') ? 'has-error' : '' }}">
                        <label for="name" class="col-md-3 control-label">{{ trans('admin/deployments/general.catalog_name') }}</label>
                        <div class="col-md-7"><input type="text" id="name" name="name" class="form-control" value="{{ old('name', $item->name) }}" required></div>
                    </div>
                    <div class="form-group">
                        <label for="color" class="col-md-3 control-label">{{ trans('admin/deployments/general.catalog_color') }}</label>
                        <div class="col-md-7"><input type="color" id="color" name="color" value="{{ old('color', $item->color ?: '#2980b9') }}" style="height:38px; width:80px;"></div>
                    </div>

                    @if ($catalog === 'stages')
                        <div class="form-group">
                            <label class="col-md-3 control-label">{{ trans('admin/deployments/general.catalog_terminal') }}</label>
                            <div class="col-md-7"><label class="checkbox-inline"><input type="checkbox" name="is_terminal" value="1" {{ old('is_terminal', $item->is_terminal ?? false) ? 'checked' : '' }}></label></div>
                        </div>
                        <div class="form-group">
                            <label for="maps_to_status_id" class="col-md-3 control-label">{{ trans('admin/deployments/general.catalog_maps_to_status') }}</label>
                            <div class="col-md-7">
                                <select id="maps_to_status_id" name="maps_to_status_id" class="form-control select2">
                                    <option value="">{{ trans('admin/deployments/general.catalog_none') }}</option>
                                    @foreach ($statuslabels as $sl)
                                        <option value="{{ $sl->id }}" {{ (int) old('maps_to_status_id', $item->maps_to_status_id ?? 0) === (int) $sl->id ? 'selected' : '' }}>{{ $sl->name }}</option>
                                    @endforeach
                                </select>
                                <p class="help-block">{{ trans('admin/deployments/general.catalog_maps_to_status_help') }}</p>
                            </div>
                        </div>
                    @endif

                    <div class="form-group">
                        <label for="sort_order" class="col-md-3 control-label">{{ trans('admin/deployments/general.catalog_sort') }}</label>
                        <div class="col-md-7"><input type="number" id="sort_order" name="sort_order" class="form-control" value="{{ old('sort_order', $item->sort_order ?? 0) }}"></div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-3 control-label">{{ trans('admin/deployments/general.catalog_active') }}</label>
                        <div class="col-md-7"><label class="checkbox-inline"><input type="checkbox" name="active" value="1" {{ old('active', $item->active ?? true) ? 'checked' : '' }}></label></div>
                    </div>
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('deployment-config.index', $catalog) }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
