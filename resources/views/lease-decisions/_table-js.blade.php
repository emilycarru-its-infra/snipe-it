{{-- Bootstrap-table formatters for the lease-decisions listing.

     Extracted so the procurement hub can render this table too: the
     presenter names these functions by string, so a page that shows the
     table without loading them prints the names instead of the rows. --}}
<script nonce="{{ csrf_token() }}">
    (function () {
        var baseUrl = "{{ route('lease-decisions.index') }}";
        var csrfToken = $('meta[name="csrf-token"]').attr('content');

        function esc(text) {
            return $('<span>').text(text == null ? '' : text).html();
        }

        window.leaseDecisionsLinkFormatter = function (value, row) {
            return '<a href="' + baseUrl + '/' + row.id + '/edit">' + esc(value) + '</a>';
        };

        window.leaseDecisionsTitleCaseFormatter = function (value) {
            if (!value) { return ''; }
            return esc(value.charAt(0).toUpperCase() + value.slice(1));
        };

        window.leaseDecisionsActionsFormatter = function (value, row) {
            var actions = row.available_actions || {};
            var html = '';
            if (actions.update) {
                html += '<a href="' + baseUrl + '/' + row.id + '/edit" class="btn btn-warning btn-sm" data-tooltip="true" title="{{ trans('general.update') }}"><i class="fas fa-pencil-alt" aria-hidden="true"></i></a> ';
            }
            if (actions.delete) {
                html += '<form method="POST" action="' + baseUrl + '/' + row.id + '" style="display:inline-block" '
                    + 'onsubmit="return confirm(\'{{ trans('admin/lease-decisions/message.delete_confirm') }}\')">'
                    + '<input type="hidden" name="_token" value="' + csrfToken + '">'
                    + '<input type="hidden" name="_method" value="DELETE">'
                    + '<button type="submit" class="btn btn-danger btn-sm" data-tooltip="true" title="{{ trans('general.delete') }}"><i class="fas fa-trash" aria-hidden="true"></i></button>'
                    + '</form>';
            }
            return html;
        };

        @can('create', \App\Models\Order::class)
        window.leaseDecisionButtons = () => ({
            btnAdd: {
                text: '{{ trans('admin/lease-decisions/general.create') }}',
                icon: 'fa fa-plus',
                event () {
                    window.location.href = '{{ route('lease-decisions.create') }}';
                },
                attributes: {
                    class: 'btn-warning',
                    title: '{{ trans('admin/lease-decisions/general.create') }}',
                },
            },
        });
        @endcan
    })();
</script>
