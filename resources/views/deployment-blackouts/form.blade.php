@extends('layouts/default')

@section('title')
    {{ $blackout->exists ? trans('admin/deployments/general.blackout_update') : trans('admin/deployments/general.blackout_create') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-8 col-md-offset-2">
        <form class="form-horizontal" method="POST" action="{{ $blackout->exists ? route('deployments.blackouts.update', $blackout->id) : route('deployments.blackouts.store') }}">
            {{ csrf_field() }}
            @if ($blackout->exists) @method('PUT') @endif
            <div class="box box-default">
                <div class="box-body">

                    @include('partials.forms.edit.user-select', [
                        'fieldname' => 'user_id',
                        'translated_name' => trans('admin/deployments/general.blackout_staff'),
                        'item' => $blackout,
                        'required' => 'true',
                    ])

                    <div class="form-group {{ $errors->has('start_date') ? 'has-error' : '' }}">
                        <label for="start_date" class="col-md-3 control-label">{{ trans('admin/deployments/general.blackout_start') }}</label>
                        <div class="col-md-7">
                            <input type="date" id="start_date" name="start_date" class="form-control" value="{{ old('start_date', optional($blackout->start_date)->toDateString()) }}" required>
                            {!! $errors->first('start_date', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
                        </div>
                    </div>

                    <div class="form-group {{ $errors->has('end_date') ? 'has-error' : '' }}">
                        <label for="end_date" class="col-md-3 control-label">{{ trans('admin/deployments/general.blackout_end') }}</label>
                        <div class="col-md-7">
                            <input type="date" id="end_date" name="end_date" class="form-control" value="{{ old('end_date', optional($blackout->end_date)->toDateString()) }}" required>
                            {!! $errors->first('end_date', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
                        </div>
                    </div>

                    <div class="form-group {{ $errors->has('reason') ? 'has-error' : '' }}">
                        <label for="reason" class="col-md-3 control-label">{{ trans('admin/deployments/general.blackout_reason') }}</label>
                        <div class="col-md-7">
                            <input type="text" id="reason" name="reason" class="form-control" maxlength="191" value="{{ old('reason', $blackout->reason) }}">
                            {!! $errors->first('reason', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
                        </div>
                    </div>

                </div>
                <div class="box-footer text-right">
                    <a class="btn btn-default" href="{{ route('deployments.blackouts.index') }}">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop
