@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/orders/general.invoices') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('reports.procurement.invoices') }}" class="btn btn-sm btn-default">{{ trans('admin/purchase-orders/general.report_invoices') }}</a>
    <a href="{{ route('reports.procurement.invoice-approval') }}" class="btn btn-sm btn-default">{{ trans('admin/purchase-orders/general.report_invoice_approval') }}</a>
@stop

{{-- Page content --}}
@section('content')
    <x-container>
        <p class="text-muted">{{ trans('admin/purchase-orders/general.invoice_page_list_intro') }}</p>

        {{-- The filters are a plain GET form: the address carries them, so a
             filtered list can be bookmarked or sent, and the table reads the
             same values from its API address. --}}
        <x-box>
            <form method="get" action="{{ route('invoices.index') }}" class="invoice-filters" role="search">
                <div class="row">
                    <div class="col-md-3 col-sm-6 form-group">
                        <label for="filter-search">{{ trans('admin/purchase-orders/general.invoice_page_filter_search') }}</label>
                        <input type="search" class="form-control input-sm" id="filter-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="191">
                    </div>
                    <div class="col-md-3 col-sm-6 form-group">
                        <label for="filter-supplier">{{ trans('admin/purchase-orders/general.invoice_page_filter_supplier') }}</label>
                        <select class="form-control input-sm" id="filter-supplier" name="supplier_id">
                            <option value="">{{ trans('admin/purchase-orders/general.invoice_page_filter_any') }}</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-4 form-group">
                        <label for="filter-status">{{ trans('admin/purchase-orders/general.invoice_page_filter_status') }}</label>
                        <select class="form-control input-sm" id="filter-status" name="approval_status">
                            <option value="">{{ trans('admin/purchase-orders/general.invoice_page_filter_any') }}</option>
                            @foreach (\App\Models\OrderInvoice::APPROVAL_STATUSES as $status)
                                <option value="{{ $status }}" @selected(($filters['approval_status'] ?? '') === $status)>{{ trans('admin/purchase-orders/general.invoice_approval_'.$status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-4 form-group">
                        <label for="filter-type">{{ trans('admin/purchase-orders/general.invoice_page_filter_type') }}</label>
                        <select class="form-control input-sm" id="filter-type" name="invoice_type">
                            <option value="">{{ trans('admin/purchase-orders/general.invoice_page_filter_any') }}</option>
                            @foreach (\App\Models\OrderInvoice::INVOICE_TYPES as $type)
                                <option value="{{ $type }}" @selected(($filters['invoice_type'] ?? '') === $type)>{{ trans('admin/purchase-orders/general.invoice_type_'.$type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 col-sm-4 form-group">
                        <label for="filter-fy">{{ trans('admin/purchase-orders/general.invoice_page_filter_fy') }}</label>
                        <select class="form-control input-sm" id="filter-fy" name="fiscal_year">
                            <option value="">{{ trans('admin/purchase-orders/general.invoice_page_filter_any') }}</option>
                            @foreach ($fiscalYears as $fy)
                                <option value="{{ $fy }}" @selected(($filters['fiscal_year'] ?? '') === (string) $fy)>{{ $fy }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-2 col-sm-4 form-group">
                        <label for="filter-from">{{ trans('admin/purchase-orders/general.invoice_page_filter_from') }}</label>
                        <input type="date" class="form-control input-sm" id="filter-from" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-md-2 col-sm-4 form-group">
                        <label for="filter-to">{{ trans('admin/purchase-orders/general.invoice_page_filter_to') }}</label>
                        <input type="date" class="form-control input-sm" id="filter-to" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-md-4 col-sm-4 form-group invoice-filter-actions">
                        <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/purchase-orders/general.invoice_page_filter_apply') }}</button>
                        @if ($filters)
                            <a href="{{ route('invoices.index') }}" class="btn btn-sm btn-default">{{ trans('admin/purchase-orders/general.invoice_page_filter_clear') }}</a>
                        @endif
                    </div>
                </div>
            </form>
        </x-box>

        <x-box>
            <x-table
                name="invoice"
                fixed_number="1"
                sort_field="invoice_date"
                sort_order="desc"
                api_url="{{ $apiUrl }}"
                :presenter="\App\Presenters\OrderInvoicePresenter::dataTableLayout()"
                export_filename="export-invoices-{{ date('Y-m-d') }}"
            />
        </x-box>
    </x-container>
@stop

@section('moar_scripts')
<style>
    .invoice-filters .form-group { margin-bottom: 10px; }
    .invoice-filter-actions { padding-top: 25px; }
    @media (max-width: 767px) { .invoice-filter-actions { padding-top: 0; } }
</style>
@include('invoices._table-js')
@include ('partials.bootstrap-table', ['exportFile' => 'invoices-export', 'search' => false])
@stop
