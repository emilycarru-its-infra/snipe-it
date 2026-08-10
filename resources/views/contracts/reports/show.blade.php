@extends('layouts/default')

@section('title')
    {{ $reportTitle }}
    @parent
@stop

@section('header_right')
    {!! $controls ?? '' !!}
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    <a href="{{ route('contracts.index') }}" class="btn btn-sm btn-default">
        {{ trans('admin/contracts/general.contracts') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-body">
                @include('contracts.reports._report-table')
            </div>
        </div>
    </div>
</div>
@stop
