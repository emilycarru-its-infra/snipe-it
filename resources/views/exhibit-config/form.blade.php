@extends('layouts/default')

@section('title')
    {{ trans('admin/exhibit-projects/general.'.$labelKey) }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-8 col-md-offset-2">
        <form class="form-horizontal" method="POST" action="{{ $item->exists ? route('exhibit-config.update', [$catalog, $item->id]) : route('exhibit-config.store', $catalog) }}">
            {{ csrf_field() }}
            @if ($item->exists) @method('PUT') @endif
            <div class="box box-default">
                <div class="box-body">
                    <div class="form-group {{ $errors->has('name') ? 'has-error' : '' }}">
                        <label for="name" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.catalog_name') }}</label>
                        <div class="col-md-7"><input type="text" id="name" name="name" class="form-control" value="{{ old('name', $item->name) }}" required></div>
                    </div>
                    <div class="form-group">
                        <label for="color" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.catalog_color') }}</label>
                        <div class="col-md-7"><input type="color" id="color" name="color" value="{{ old('color', $item->color ?: '#3498db') }}" style="height:38px; width:80px;"></div>
                    </div>
                    <div class="form-group">
                        <label for="sort_order" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.catalog_sort') }}</label>
                        <div class="col-md-7"><input type="number" id="sort_order" name="sort_order" class="form-control" value="{{ old('sort_order', $item->sort_order ?? 0) }}"></div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.catalog_active') }}</label>
                        <div class="col-md-7"><label class="checkbox-inline"><input type="checkbox" name="active" value="1" {{ old('active', $item->active ?? true) ? 'checked' : '' }}></label></div>
                    </div>
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('exhibit-config.index', $catalog) }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
