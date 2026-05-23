@extends('layouts/default')

@section('title')
    {{ trans('admin/lease-schedules/general.update') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <form class="form-horizontal" method="POST" action="{{ route('lease-schedules.update', $schedule) }}">
            {{ csrf_field() }}
            @method('PATCH')
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/lease-schedules/general.update') }}</h3>
                </div>
                <div class="box-body">
                    @include('lease-schedules/_form', ['schedule' => $schedule])
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('lease-schedules.show', $schedule) }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
