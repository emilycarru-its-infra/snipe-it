@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/lease-decisions/general.lease_decisions') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
    <x-container>
        <div class="alert alert-info" style="margin-bottom: 15px;">
            <strong>{{ trans('admin/lease-decisions/general.lease_decisions') }}.</strong>
            {{ trans('admin/lease-decisions/general.help_intro') }}
        </div>
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
@include('lease-decisions._table-js')
@include ('partials.bootstrap-table', ['exportFile' => 'lease-decisions-export', 'search' => true])
@stop
