{{-- Bootstrap-table formatters for the orders listing.

     Extracted so the procurement hub can render this table too: the
     presenter names these functions by string, so a page that shows the
     table without loading them prints the names instead of the rows. --}}
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
