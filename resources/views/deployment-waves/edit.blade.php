@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.update') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <form class="form-horizontal" method="POST" action="{{ route('deployment-waves.update', $wave) }}">
            {{ csrf_field() }}
            @method('PATCH')
            <div class="box box-default">
                <div class="box-body">
                    @include('deployment-waves/_form', ['wave' => $wave, 'types' => $types])
                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('deployment-waves.show', $wave) }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
