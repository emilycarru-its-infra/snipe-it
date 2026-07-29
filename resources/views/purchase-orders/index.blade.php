@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/purchase-orders/general.purchase_orders') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
    <x-container>
        <x-box>

            <x-slot:bulkactions>
                {{-- The builder sits at the head of the toolbar because it is
                     where a purchase order starts. It is deep-linked to the
                     current fiscal year: the year is almost always this one,
                     and a preselected picker is one less field to remember. --}}
                <div class="po-toolbar">
                    @can('create', App\Models\Order::class)
                        <a href="{{ route('purchase-orders.builder', ['fiscal_year' => \App\Helpers\Helper::currentFiscalYear()]) }}"
                           class="btn btn-primary">
                            <i class="fas fa-calculator" aria-hidden="true"></i>
                            {{ trans('admin/purchase-orders/general.open_builder') }}
                        </a>
                    @endcan

                    <x-table.bulk-actions
                            name='purchaseorder'
                            action_route="{{ route('purchase-orders.bulk.delete') }}"
                            model_name="purchase_orders"
                    >
                        @can('delete', App\Models\Order::class)
                            <option>{{ trans('general.delete') }}</option>
                        @endcan
                    </x-table.bulk-actions>
                </div>
            </x-slot:bulkactions>

            <x-table
                name="purchaseorder"
                buttons="purchaseOrderButtons"
                fixed_right_number="1"
                fixed_number="1"
                sort_field="po_number"
                api_url="{{ route('api.purchase-orders.index') }}"
                :presenter="\App\Presenters\PurchaseOrderPresenter::dataTableLayout()"
                export_filename="export-purchase-orders-{{ date('Y-m-d') }}"
            />

        </x-box>
    </x-container>
@stop

@section('moar_scripts')
<style>
    /* Keeps the builder link on the same line as the bulk-action form, which
       renders as a block element of its own. */
    .po-toolbar { display: flex; align-items: flex-start; gap: 10px; }
    .po-toolbar > .btn { white-space: nowrap; }
</style>
@include('purchase-orders._table-js')
@include ('partials.bootstrap-table', ['exportFile' => 'purchase-orders-export', 'search' => true])
@stop
