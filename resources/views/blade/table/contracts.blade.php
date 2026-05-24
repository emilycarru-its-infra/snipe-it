@props([
    'route' => route('api.contracts.index'),
    'name' => 'default',
    'presenter' => \App\Presenters\ContractPresenter::dataTableLayout(),
    'fixed_right_number' => 1,
    'fixed_number' => 1,
    'show_search' => true,
    'show_advanced_search' => false,
    'show_column_search' => false,
    'table_header' => trans('admin/contracts/general.contracts'),
])

@can('view', \App\Models\Contract::class)

    <x-slot:table_header>
        {{ $table_header }}
    </x-slot:table_header>

    <x-table
        :$presenter
        :$fixed_right_number
        :$fixed_number
        :$show_search
        :$show_column_search
        :$show_advanced_search
        api_url="{{ $route }}"
        export_filename="export-{{ str_slug($name) }}-contracts-{{ date('Y-m-d') }}"
    />

@endcan
