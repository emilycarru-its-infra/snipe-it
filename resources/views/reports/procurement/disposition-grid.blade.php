@extends('layouts/default')

@section('title')
    {{ $reportTitle }}
    @parent
@stop

@section('header_right')
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
    <a href="{{ route('reports.procurement') }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.reports') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-body">
                <p class="text-muted">{{ $reportIntro }}</p>
                @include('reports.procurement._disposition-grid', [
                    'contracts' => $contracts,
                    'canEdit' => $canEdit,
                ])
            </div>
        </div>
    </div>
</div>
@include('reports.procurement._disposition-grid-js')
@stop
