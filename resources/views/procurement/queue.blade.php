@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.queue') }}
    @parent
@stop

@section('header_right')
    <form method="get" style="display:inline-block;">
        <select name="status" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
            @foreach (array_merge($statuses, ['all']) as $status)
                <option value="{{ $status }}" {{ $selectedStatus === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('procurement.index') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.procurement') }}</a>
@stop

@section('content')

<p class="text-muted">{{ trans('admin/store/general.queue_intro') }}</p>

@include('procurement._queue-list')
@stop
