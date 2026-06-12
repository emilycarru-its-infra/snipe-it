@extends('layouts/default')

{{-- Page title --}}
@section('title')
{{ trans('general.activity_report') }} 
@parent
@stop

@section('header_right')
    <form method="POST" action="{{ route('reports.activity.post') }}" accept-charset="UTF-8" class="form-horizontal">
    {{csrf_field()}}
    <button type="submit" class="btn btn-default">
        <x-icon type="download" />
        {{ trans('general.download_all') }}
    </button>
    </form>
@stop

{{-- Page content --}}
@section('content')
    <x-container>
        <x-box>

                <div class="btn-group" role="group" id="activity-type-filter" style="margin-bottom: 15px;">
                    <button type="button" class="btn btn-default active" data-item-type="">{{ trans('general.all') }}</button>
                    <button type="button" class="btn btn-default" data-item-type="Asset">{{ trans('general.assets') }}</button>
                    <button type="button" class="btn btn-default" data-item-type="Accessory">{{ trans('general.accessories') }}</button>
                    <button type="button" class="btn btn-default" data-item-type="Consumable">{{ trans('general.consumables') }}</button>
                    <button type="button" class="btn btn-default" data-item-type="Component">{{ trans('general.components') }}</button>
                    <button type="button" class="btn btn-default" data-item-type="License">{{ trans('general.licenses') }}</button>
                    <button type="button" class="btn btn-default" data-item-type="User">{{ trans('general.users') }}</button>
                </div>

                <table
                    data-columns="{{ \App\Presenters\HistoryPresenter::dataTableLayout() }}"
                        data-cookie-id-table="activityReport"
                        data-id-table="activityReport"
                        data-side-pagination="server"
                        data-advanced-search="false"
                        data-sort-order="desc"
                        data-sort-name="created_at"
                        id="activityReport"
                        data-url="{{ route('api.activity.index') }}"
                        class="table table-striped snipe-table"
                        data-export-options='{
                        "fileName": "activity-report-{{ date('Y-m-d') }}",
                        "ignoreColumn": ["actions","image","change","checkbox","checkincheckout","icon"]
                        }'>
                </table>
        </x-box>
    </x-container>
@stop


@section('moar_scripts')
@include ('partials.bootstrap-table', ['exportFile' => 'activity-export', 'search' => true])

<script nonce="{{ csrf_token() }}">
    (function () {
        var baseUrl = '{{ route('api.activity.index') }}';

        $('#activity-type-filter').on('click', 'button', function () {
            var $button = $(this);
            if ($button.hasClass('active')) {
                return;
            }

            $button.addClass('active').siblings().removeClass('active');

            var itemType = $button.data('item-type');
            var url = itemType ? baseUrl + '?item_type=' + encodeURIComponent(itemType) : baseUrl;

            $('#activityReport').bootstrapTable('refresh', { url: url, pageNumber: 1 });
        });
    })();
</script>
@stop
