@extends('layouts/basic')

{{-- Page title --}}
@section('title')
404
@parent
@stop

{{-- Page content --}}

@section('content')
    @include('errors._error-page', [
        'code' => '404',
        'headline' => trans('general.error_404_headline'),
        'message' => trans('general.error_404_message'),
        'action_url' => config('app.url'),
        'action_label' => trans('general.error_back_to_dashboard'),
    ])
@stop
