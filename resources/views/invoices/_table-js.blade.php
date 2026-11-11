{{-- Bootstrap-table formatters for the invoices list, here and on the
     procurement hub: the presenter names them by string, so a page that shows
     the table without loading them prints the names instead of the rows. --}}
<script nonce="{{ csrf_token() }}">
    (function () {
        var approvalLabels = @json(collect(\App\Models\OrderInvoice::APPROVAL_STATUSES)->mapWithKeys(fn ($s) => [$s => trans('admin/purchase-orders/general.invoice_approval_'.$s)]));
        var typeLabels = @json(collect(\App\Models\OrderInvoice::INVOICE_TYPES)->mapWithKeys(fn ($t) => [$t => trans('admin/purchase-orders/general.invoice_type_'.$t)]));
        var approvalClasses = { pending: 'label-warning', approved: 'label-success', disputed: 'label-danger' };

        function esc(text) {
            return $('<span>').text(text == null ? '' : text).html();
        }

        function link(url, label) {
            return url ? '<a href="' + esc(url) + '">' + esc(label) + '</a>' : esc(label);
        }

        window.invoicesLinkFormatter = function (value, row) {
            return link(row.url, value);
        };

        window.invoicesObjNameFormatter = function (value) {
            return (value && value.name) ? esc(value.name) : '';
        };

        window.invoicesOrderFormatter = function (value) {
            if (!value) { return ''; }
            var html = link(value.url, value.display_number);
            if (value.secondary_number) {
                html += ' <small class="text-muted">' + esc(value.secondary_number) + '</small>';
            }
            return html;
        };

        window.invoicesPurchaseOrderFormatter = function (value) {
            return value ? link(value.url, value.po_number) : '';
        };

        window.invoicesTypeFormatter = function (value) {
            return esc(typeLabels[value || 'regular'] || value);
        };

        window.invoicesApprovalFormatter = function (value) {
            var status = value || 'pending';
            return '<span class="label ' + (approvalClasses[status] || 'label-default') + '">' + esc(approvalLabels[status] || status) + '</span>';
        };
    })();
</script>
