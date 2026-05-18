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
                <x-table.bulk-actions
                        name='purchaseorder'
                        action_route="{{ route('purchase-orders.bulk.delete') }}"
                        model_name="purchase_orders"
                >
                    @can('delete', App\Models\Order::class)
                        <option>{{ trans('general.delete') }}</option>
                    @endcan
                </x-table.bulk-actions>
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
<script nonce="{{ csrf_token() }}">
    (function () {
        var baseUrl = "{{ route('purchase-orders.index') }}";
        var csrfToken = $('meta[name="csrf-token"]').attr('content');

        function esc(text) {
            return $('<span>').text(text == null ? '' : text).html();
        }

        window.purchaseOrdersLinkFormatter = function (value, row) {
            return '<a href="' + baseUrl + '/' + row.id + '">' + esc(value) + '</a>';
        };

        window.purchaseOrdersObjNameFormatter = function (value) {
            return (value && value.name) ? esc(value.name) : '';
        };

        window.purchaseOrdersStatusFormatter = function (value) {
            if (!value) { return ''; }
            return esc(value.charAt(0).toUpperCase() + value.slice(1));
        };

        window.purchaseOrdersRemainingFormatter = function (value, row) {
            if (value == null) { return ''; }
            if (row.over_budget) {
                return '<span class="text-danger">' + esc(value) + '</span>';
            }
            return esc(value);
        };

        window.purchaseOrdersActionsFormatter = function (value, row) {
            var actions = row.available_actions || {};
            var html = '';
            if (actions.update) {
                html += '<a href="' + baseUrl + '/' + row.id + '/edit" class="btn btn-warning btn-sm" data-tooltip="true" title="{{ trans('general.update') }}"><i class="fas fa-pencil-alt" aria-hidden="true"></i></a> ';
            }
            if (actions.delete) {
                html += '<form method="POST" action="' + baseUrl + '/' + row.id + '" style="display:inline-block" '
                    + 'onsubmit="return confirm(\'{{ trans('admin/purchase-orders/message.delete_confirm') }}\')">'
                    + '<input type="hidden" name="_token" value="' + csrfToken + '">'
                    + '<input type="hidden" name="_method" value="DELETE">'
                    + '<button type="submit" class="btn btn-danger btn-sm" data-tooltip="true" title="{{ trans('general.delete') }}"><i class="fas fa-trash" aria-hidden="true"></i></button>'
                    + '</form>';
            }
            return html;
        };

        @can('create', \App\Models\Order::class)
        window.purchaseOrderButtons = () => ({
            btnAdd: {
                text: '{{ trans('admin/purchase-orders/general.create') }}',
                icon: 'fa fa-plus',
                event () {
                    window.location.href = '{{ route('purchase-orders.create') }}';
                },
                attributes: {
                    class: 'btn-warning',
                    title: '{{ trans('admin/purchase-orders/general.create') }}',
                },
            },
        });
        @endcan
    })();
</script>
@include ('partials.bootstrap-table', ['exportFile' => 'purchase-orders-export', 'search' => true])
@stop
