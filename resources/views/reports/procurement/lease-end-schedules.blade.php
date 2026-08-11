@extends('layouts/default')

@section('title')
    {{ trans('admin/purchase-orders/general.report_lease_end_schedules') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('reports.procurement') }}" class="btn btn-sm btn-default">{{ trans('admin/purchase-orders/general.reports') }}</a>
    <a href="{{ route('reports.procurement.lease-end-schedules', array_filter(['fiscal_year' => $selectedFy, 'format' => 'csv'])) }}" class="btn btn-sm btn-default">
        <i class="fas fa-download"></i> {{ trans('admin/purchase-orders/general.disposition_download_csv') }}
    </a>
@stop

@section('content')
<div class="proc-pipe">
    @include('reports.procurement._lease-end-schedules')
</div>
@stop

@section('moar_scripts')
    @include('reports.procurement._report-note-js')
@stop
