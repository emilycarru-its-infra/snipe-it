@extends('layouts/default')

@section('title')
    {{ trans('admin/orders/general.allocation_heading') }}
    @parent
@stop

{{-- Hardware that arrived without a matching request, on its own page —
     the orders list is for orders; this is the workbench for the units
     still waiting to be given to somebody's request. --}}

@section('header_right')
    <a href="{{ route('orders.index') }}" class="btn btn-sm btn-default">{{ trans('admin/orders/general.orders') }}</a>
@stop

@section('content')

<style>
.ord-meta { opacity: .65; font-size: 12px; }
.ord-alloc { border: 1px solid light-dark(#f0ad4e, #8a6d3b); border-radius: 10px; padding: 14px 16px;
    margin-bottom: 18px; background: light-dark(#fcf8f0, #2a2620); }
.ord-alloc-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 6px 0;
    border-top: 1px solid light-dark(#eee3cc, #3a3427); }
.ord-alloc-row:first-of-type { border-top: 0; }
</style>

@if ($arrivals->isEmpty())
    <div class="box box-default"><div class="box-body">
        <p class="text-muted" style="margin:0;">{{ trans('admin/orders/general.allocation_all_clear') }}</p>
    </div></div>
@else
    @include('orders._allocation')
@endif

@stop
