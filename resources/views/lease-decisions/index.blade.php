@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/lease-decisions/general.lease_decisions') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
    <x-container>
        <x-box>

            <x-slot:bulkactions>
                <x-table.bulk-actions
                        name='leasedecision'
                        action_route="{{ route('lease-decisions.bulk.delete') }}"
                        model_name="lease_decisions"
                >
                    @can('delete', App\Models\Order::class)
                        <option>{{ trans('general.delete') }}</option>
                    @endcan
                </x-table.bulk-actions>
            </x-slot:bulkactions>

            <x-table
                name="leasedecision"
                buttons="leaseDecisionButtons"
                fixed_right_number="1"
                fixed_number="1"
                sort_field="contract_reference"
                api_url="{{ route('api.lease-decisions.index') }}"
                :presenter="\App\Presenters\LeaseDecisionPresenter::dataTableLayout()"
                export_filename="export-lease-decisions-{{ date('Y-m-d') }}"
            />

        </x-box>
    </x-container>
@stop

@section('moar_scripts')
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
@include ('partials.bootstrap-table', ['exportFile' => 'lease-decisions-export', 'search' => true])
@stop
