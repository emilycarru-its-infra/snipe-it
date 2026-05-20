@extends('layouts/default')

@section('title')
    {{ trans('admin/toners/general.toners') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('consumables.index') }}" class="btn btn-sm btn-default">
        <x-icon type="consumables" class="fa-fw" />
        {{ trans('general.consumables') }}
    </a>
@stop

@section('content')
@include('toners._dashboard')
@if ($totalModels === 0)
    <div class="row">
        <div class="col-md-12">
            <div class="callout callout-info">
                <i class="fa-solid fa-circle-info"></i>
                {{ trans('admin/toners/general.empty_state') }}
                <a href="{{ route('consumables.index') }}">{{ trans('general.consumables') }}</a>.
            </div>
        </div>
    </div>
@endif
@stop
