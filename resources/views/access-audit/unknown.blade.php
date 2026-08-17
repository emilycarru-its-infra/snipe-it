@extends('layouts/default')

@section('title')
    {{ trans('admin/access-audit/general.title') }} @parent
@stop

@section('content')
<x-container>
    <x-box>
        <h3 style="margin-top: 0;">{{ trans('admin/access-audit/general.unknown_page') }}</h3>
        <p class="text-muted">{{ trans('admin/access-audit/general.unknown_help') }}</p>
        @if ($path !== '')
            <p><code>{{ $path }}</code></p>
        @endif
        <a href="{{ route('groups.audit') }}" target="_blank" rel="noopener" class="btn btn-default">
            {{ trans('admin/access-audit/general.full_matrix') }}
        </a>
    </x-box>
</x-container>
@stop
