@extends('layouts/basic')

{{-- Page title --}}
@section('title')
  {{ trans('general.maintenance_mode_title') }}
@parent
@stop

{{-- Page content --}}

@section('content')
    @include('errors._error-page', [
        'code' => '503',
        'headline' => trans('general.maintenance_mode_title'),
        'message' => trans('general.maintenance_mode'),
        'action_url' => config('app.url'),
        'action_label' => trans('general.error_try_again'),
    ])
@stop
