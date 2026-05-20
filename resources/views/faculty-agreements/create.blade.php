@extends('layouts/default')

@section('title')
    {{ trans('admin/faculty-agreements/general.create') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <form class="form-horizontal" method="POST" action="{{ route('faculty-agreements.store') }}">
            {{ csrf_field() }}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/faculty-agreements/general.create') }}</h3>
                </div>
                <div class="box-body">
                    @include('faculty-agreements/_form', ['agreement' => null])
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('reports.procurement.faculty-ledger') }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
