@extends('layouts/default')

@section('title')
    {{ trans('admin/exhibit-projects/general.import_title') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-8 col-md-offset-2">
        <form class="form-horizontal" method="POST" action="{{ route('exhibit-projects.import') }}" enctype="multipart/form-data">
            {{ csrf_field() }}
            <div class="box box-default">
                <div class="box-header with-border"><h3 class="box-title">{{ trans('admin/exhibit-projects/general.import_title') }}</h3></div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/exhibit-projects/general.import_help') }}</p>
                    <div class="form-group {{ $errors->has('exhibit_id') ? 'has-error' : '' }}">
                        <label for="exhibit_id" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.show') }}</label>
                        <div class="col-md-7">
                            <select id="exhibit_id" name="exhibit_id" class="form-control select2">
                                @foreach ($exhibits as $exhibit)
                                    <option value="{{ $exhibit->id }}">{{ $exhibit->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="form-group {{ $errors->has('year') ? 'has-error' : '' }}">
                        <label for="year" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.year') }}</label>
                        <div class="col-md-7"><input type="number" id="year" name="year" class="form-control" value="{{ old('year', date('Y')) }}" min="2000" max="2100"></div>
                    </div>
                    <div class="form-group {{ $errors->has('file') ? 'has-error' : '' }}">
                        <label for="file" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.import_file') }}</label>
                        <div class="col-md-7"><input type="file" id="file" name="file" accept=".csv,text/csv" required></div>
                    </div>
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('reports.exhibit') }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> {{ trans('admin/exhibit-projects/general.import_run') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
