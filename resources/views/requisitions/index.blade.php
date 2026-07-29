@extends('layouts/default')

@section('title')
    {{ trans('admin/purchase-orders/general.requisitions') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('procurement.index') }}" class="btn btn-sm btn-default">
        {{ trans('admin/store/general.procurement') }}
    </a>
@stop

@section('content')
    <x-container>
        <x-box>
            <x-table
                name="requisition"
                buttons="requisitionButtons"
                fixed_right_number="1"
                sort_field="created_at"
                sort_order="desc"
                api_url="{{ route('api.requisitions.index') }}"
                :presenter="\App\Presenters\RequisitionPresenter::dataTableLayout()"
                export_filename="export-requisitions-{{ date('Y-m-d') }}"
            />
        </x-box>
    </x-container>
@stop

@section('moar_scripts')
@include('requisitions._table-js')
@include ('partials.bootstrap-table', ['exportFile' => 'requisitions-export', 'search' => true])
@stop
