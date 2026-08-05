@extends('layouts/basic')

{{-- Page title --}}
@section('title')
403
@parent
@stop

{{-- Page content --}}

@section('content')
    @include('errors._error-page', [
        'code' => '403',
        'headline' => trans('general.error_403_headline'),
        'message' => trans('general.error_403_message'),
        'action_url' => config('app.url'),
        'action_label' => trans('general.error_back_to_dashboard'),
    ])
@stop
