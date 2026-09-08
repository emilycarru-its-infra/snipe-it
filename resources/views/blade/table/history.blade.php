@props([
    'route',
    'name' => 'default',
    'table_header' => trans('general.history'),
    'model' => null,
    'hide_fields' => [],
])

<!-- start history tab pane -->
@can('history', $model)
    <x-slot:table_header>
        {{ $table_header }}
    </x-slot:table_header>

    <x-table
        :presenter="\App\Presenters\HistoryPresenter::dataTableLayout($hide_fields)"
        show_advanced_search="false"
        api_url="{{ $route }}"
        fixed_number="false"
        fixed_right_number="false"
        export_filename="export-history-{{ date('Y-m-d') }}"
        {{-- Newest first. History is read to find out what just happened, and
             the API already answers newest-first by default — it was the table
             asking for ascending that put the oldest row on top. --}}
        sort_field="created_at"
        sort_order="desc"
    />
@endcan
<!-- end assets tab pane -->