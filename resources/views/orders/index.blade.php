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
<script nonce="{{ csrf_token() }}">
    (function () {
        var ordersBaseUrl = "{{ route('orders.index') }}";
        var csrfToken = $('meta[name="csrf-token"]').attr('content');

        function esc(text) {
            return $('<span>').text(text == null ? '' : text).html();
        }

        window.ordersLinkFormatter = function (value, row) {
            return '<a href="' + ordersBaseUrl + '/' + row.id + '">' + esc(value) + '</a>';
        };

        window.ordersObjNameFormatter = function (value) {
            return (value && value.name) ? esc(value.name) : '';
        };

        window.ordersStatusFormatter = function (value, row) {
            var label = value ? esc(value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ')) : '';
            if (row && row.is_planned) {
                label += ' <span class="label label-info">{{ trans('admin/orders/general.planned') }}</span>';
            }
            return label;
        };

        window.ordersActionsFormatter = function (value, row) {
            var actions = row.available_actions || {};
            var html = '';
            if (actions.update) {
                html += '<a href="' + ordersBaseUrl + '/' + row.id + '/edit" class="btn btn-warning btn-sm" data-tooltip="true" title="{{ trans('general.update') }}"><i class="fas fa-pencil-alt" aria-hidden="true"></i></a> ';
            }
            if (actions.delete) {
                html += '<form method="POST" action="' + ordersBaseUrl + '/' + row.id + '" style="display:inline-block" '
                    + 'onsubmit="return confirm(\'{{ trans('admin/orders/message.delete_confirm') }}\')">'
                    + '<input type="hidden" name="_token" value="' + csrfToken + '">'
                    + '<input type="hidden" name="_method" value="DELETE">'
                    + '<button type="submit" class="btn btn-danger btn-sm" data-tooltip="true" title="{{ trans('general.delete') }}"><i class="fas fa-trash" aria-hidden="true"></i></button>'
                    + '</form>';
            }
            return html;
        };

        @can('create', \App\Models\Order::class)
        window.orderButtons = () => ({
            btnAdd: {
                text: '{{ trans('admin/orders/general.create') }}',
                icon: 'fa fa-plus',
                event () {
                    window.location.href = '{{ route('orders.create') }}';
                },
                attributes: {
                    class: 'btn-warning',
                    title: '{{ trans('admin/orders/general.create') }}',
                },
            },
        });
        @endcan
    })();
</script>
@include ('partials.bootstrap-table', ['exportFile' => 'orders-export', 'search' => true])
@stop
