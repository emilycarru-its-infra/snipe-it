@extends('layouts/default')

{{-- Web site Title --}}
@section('title')
{{ trans('admin/groups/titles.group_management') }}
@parent
@stop

@section('header_right')
    <a href="{{ route('groups.audit') }}" class="btn btn-default pull-right" style="margin-left: 5px;">
        <i class="fas fa-table" aria-hidden="true"></i>
        {{ trans('admin/groups/general.audit_title') }}
    </a>
@stop

{{-- Page content --}}
@section('content')
    <x-container>
        <x-box>

            <x-table
                    name="groups"
                    buttons="groupButtons"
                    fixed_right_number="1"
                    fixed_number="1"
                    api_url="{{ route('api.groups.index') }}"
                    :presenter="\App\Presenters\GroupPresenter::dataTableLayout()"
                    export_filename="export-groups-{{ date('Y-m-d') }}"
            />
            
        </x-box>
    </x-container>
@stop
@section('moar_scripts')
@include ('partials.bootstrap-table', ['exportFile' => 'groups-export', 'search' => true])
@stop
