@extends('layouts/default')

@section('title')
    {{ trans('admin/faculty-agreements/general.update') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <form class="form-horizontal" method="POST" action="{{ route('faculty-agreements.update', $agreement) }}">
            {{ csrf_field() }}
            @method('PATCH')
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/faculty-agreements/general.update') }}</h3>
                </div>
                <div class="box-body">
                    @include('faculty-agreements/_form', ['agreement' => $agreement])
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('faculty-agreements.show', $agreement) }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
