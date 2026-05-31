@extends('layouts/default')

@section('title')
    {{ trans('admin/user-agreements/general.create') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <form class="form-horizontal" method="POST" action="{{ route('user-agreements.store') }}">
            {{ csrf_field() }}
            <div class="box box-default">
                <div class="box-body">
                    @include('user-agreements/_form', ['agreement' => null])
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('reports.procurement.user-agreement-ledger') }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
