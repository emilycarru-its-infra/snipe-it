@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.queue') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('procurement.index') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.procurement') }}</a>
@stop

@push('css')
<style>
{{-- Left with the content it filters, not floating off in the page
     header; and colored from the theme tokens — the hard-coded white
     pills were unreadable chips in dark mode. --}}
.ap-filters { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 14px; }
.ap-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 999px;
    border: 1px solid var(--box-border-color, #d8d8dc);
    background: var(--box-bg, #fff);
    color: var(--color-fg, #444);
    font-size: 13px; line-height: 20px; text-decoration: none; white-space: nowrap;
}
.ap-pill:hover, .ap-pill:focus {
    background: color-mix(in srgb, var(--color-fg, #444) 7%, var(--box-bg, #fff));
    color: var(--color-fg, #222);
    text-decoration: none;
}
.ap-pill.is-on {
    background: var(--color-fg, #2f3640);
    border-color: var(--color-fg, #2f3640);
    color: var(--box-bg, #fff);
}
/* The count reads as part of the pill, not a second control. */
.ap-count { font-size: 11px; font-weight: 700; opacity: .75; }
.ap-pill.is-on .ap-count { opacity: .85; }
</style>
@endpush

@section('content')

<p class="text-muted">{{ trans('admin/store/general.queue_intro') }}</p>

{{-- Pills rather than a select. The state of this page is the question
     it answers — how many are waiting on me, how many are approved and
     still to go out — and a closed dropdown showed neither: it hid both
     the counts and the fact that other states existed at all. --}}
<nav class="ap-filters" aria-label="{{ trans('admin/store/general.queue_filter_label') }}">
    @foreach (array_merge(['all'], $statuses) as $status)
        <a href="{{ route('procurement.approvals', $status === 'all' ? [] : ['status' => $status]) }}"
           class="ap-pill {{ $selectedStatus === $status ? 'is-on' : '' }}"
           @if ($selectedStatus === $status) aria-current="page" @endif>
            {{ trans('admin/store/general.queue_status_'.$status) }}
            @if (($statusCounts[$status] ?? 0) > 0)
                <span class="ap-count">{{ $statusCounts[$status] }}</span>
            @endif
        </a>
    @endforeach
</nav>

@include('procurement._queue-list')
@stop
