@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/orders/general.orders') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
    <x-container>
        <x-box>

            <x-slot:bulkactions>
                <x-table.bulk-actions
                        name='order'
                        action_route="{{ route('orders.bulk.delete') }}"
                        model_name="order"
                >
                    @can('delete', App\Models\Order::class)
                        <option>{{ trans('general.delete') }}</option>
                    @endcan
                </x-table.bulk-actions>
            </x-slot:bulkactions>

            <x-table
                name="order"
                buttons="orderButtons"
                fixed_right_number="1"
                fixed_number="1"
                sort_field="order_number"
                api_url="{{ route('api.orders.index') }}"
                :presenter="\App\Presenters\OrderPresenter::dataTableLayout()"
                export_filename="export-orders-{{ date('Y-m-d') }}"
            />

        </x-box>
    </x-container>
@stop

@section('moar_scripts')
@include('orders._table-js')
@include ('partials.bootstrap-table', ['exportFile' => 'orders-export', 'search' => true])
@stop
