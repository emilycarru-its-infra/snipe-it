@extends('layouts/default')

@section('title')
    {{ trans('admin/exhibit-projects/general.create') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <form class="form-horizontal" method="POST" action="{{ route('exhibit-projects.store') }}">
            {{ csrf_field() }}
            <div class="box box-default">
                <div class="box-body">
                    @include('exhibit-projects/_form', ['project' => $project])
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('reports.exhibit') }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
