@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/contracts/general.contracts') }}
    @parent
@stop

@section('header_right')
    @can('create', App\Models\Contract::class)
        <a href="{{ route('contracts.create') }}" class="btn btn-primary pull-right">
            {{ trans('general.create') }}
        </a>
    @endcan
@stop

{{-- Page content --}}
@section('content')
    <x-container>
        <x-box>
            <x-table.contracts
                fixed_right_number="1"
                fixed_number="1"
                show_advanced_search="true"
                name="contracts"
            />
        </x-box>
    </x-container>
@stop

@section('moar_scripts')
    @include('partials.bootstrap-table')
@stop
