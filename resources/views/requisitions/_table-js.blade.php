{{-- Formatters for the requisitions bootstrap-table. Included once per page
     that renders the table — the requisitions index and the procurement hub
     — so both lists behave identically. --}}
<script nonce="{{ csrf_token() }}">
    (function () {
        var baseUrl = "{{ route('requisitions.index') }}";
        var builderUrl = "{{ route('purchase-orders.builder') }}";
        var poUrl = "{{ route('purchase-orders.index') }}";
        var csrfToken = $('meta[name="csrf-token"]').attr('content');

        function esc(text) {
            return $('<span>').text(text == null ? '' : text).html();
        }

        window.requisitionsLinkFormatter = function (value, row) {
            return '<a href="' + baseUrl + '/' + row.id + '">' + esc(value) + '</a>';
        };

        window.requisitionsObjNameFormatter = function (value) {
            return (value && value.name) ? esc(value.name) : '';
        };

        // Status carries the whole lifecycle, so it is worth colouring:
        // ordered is done, cancelled is dead, the rest are in flight.
        window.requisitionsStatusFormatter = function (value) {
            if (!value) { return ''; }
            var labels = {
                draft: 'default',
                submitted: 'info',
                requisitioned: 'warning',
                ordered: 'success',
                cancelled: 'danger'
            };
            return '<span class="label label-' + (labels[value] || 'default') + '">'
                + esc(value.charAt(0).toUpperCase() + value.slice(1)) + '</span>';
        };

        window.requisitionsCurrencyFormatter = function (value) {
            if (value == null) { return ''; }
            return esc(Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            }));
        };

        window.requisitionsPurchaseOrderFormatter = function (value) {
            if (!value || !value.po_number) { return ''; }
            return '<a href="' + poUrl + '/' + value.id + '">' + esc(value.po_number) + '</a>';
        };

        window.requisitionsActionsFormatter = function (value, row) {
            var actions = row.available_actions || {};
            var html = '';
            if (actions.edit_basket) {
                html += '<a href="' + builderUrl + '?requisition=' + row.id + '" class="btn btn-warning btn-sm" '
                    + 'data-tooltip="true" title="{{ trans('admin/purchase-orders/general.requisition_open_builder') }}">'
                    + '<i class="fas fa-pencil-alt" aria-hidden="true"></i></a> ';
            }
            if (actions.delete) {
                html += '<form method="POST" action="' + baseUrl + '/' + row.id + '" style="display:inline-block" '
                    + 'onsubmit="return confirm(\'{{ trans('general.delete_confirm') }}\')">'
                    + '<input type="hidden" name="_token" value="' + csrfToken + '">'
                    + '<input type="hidden" name="_method" value="DELETE">'
                    + '<button type="submit" class="btn btn-danger btn-sm" data-tooltip="true" '
                    + 'title="{{ trans('general.delete') }}"><i class="fas fa-trash" aria-hidden="true"></i></button>'
                    + '</form>';
            }
            return html;
        };

        @can('create', \App\Models\Requisition::class)
        window.requisitionButtons = () => ({
            btnAdd: {
                text: '{{ trans('admin/purchase-orders/general.report_po_builder') }}',
                icon: 'fa fa-plus',
                event () {
                    window.location.href = '{{ route('purchase-orders.builder', ['fiscal_year' => \App\Helpers\Helper::currentFiscalYear()]) }}';
                },
                attributes: {
                    class: 'btn-warning',
                    title: '{{ trans('admin/purchase-orders/general.report_po_builder') }}',
                },
            },
        });
        @endcan
    })();
</script>
