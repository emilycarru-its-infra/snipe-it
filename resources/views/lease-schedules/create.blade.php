@extends('layouts/default')

@section('title')
    {{ trans('admin/lease-schedules/general.create') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <form class="form-horizontal" method="POST" action="{{ route('lease-schedules.store') }}">
            {{ csrf_field() }}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/lease-schedules/general.create') }}</h3>
                </div>
                <div class="box-body">
                    @include('lease-schedules/_form', ['schedule' => null])
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('reports.procurement.schedule-signing') }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
