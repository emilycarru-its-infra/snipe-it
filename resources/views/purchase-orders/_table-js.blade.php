{{-- Bootstrap-table formatters for the purchase-orders listing.

     Extracted so the procurement hub can render this table too: the
     presenter names these functions by string, so a page that shows the
     table without loading them prints the names instead of the rows. --}}
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
