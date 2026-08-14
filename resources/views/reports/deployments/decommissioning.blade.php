@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.decom_title') }}
    @parent
@stop

{{-- Decommissioning gets its own address. The board keeps the lane as its
     bottom section; this page is the same lane for the person whose whole
     job today is the outgoing pile, without the rest of the board around
     it. --}}
@section('content')

<style>
    .dp-rail-scroll { overflow-x: auto; margin-bottom: 15px; }
    .dp-rail { display: flex; min-width: 900px; padding: 2px 0; }
    .dp-chev {
        flex: 1 1 0; position: relative; padding: 10px 16px 12px 30px;
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%, 16px 50%);
        margin-right: -11px;
        text-decoration: none;
        cursor: pointer;
        background: color-mix(in srgb, var(--dp-c) 10%, var(--box-bg, #fff));
    }
    .dp-chev:first-child {
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%);
        padding-left: 18px;
    }
    .dp-chev .dp-stage { font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--dp-c); }
    .dp-chev .dp-big { font-size: 20px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; color: var(--color-fg, #333); }
    .dp-scroll { max-height: 65vh; overflow: auto; }
    .dp-scroll thead th {
        position: sticky; top: 0; z-index: 2;
        background: var(--box-bg, #fff);
        box-shadow: 0 1px 0 var(--box-border-color, #f4f4f4);
    }
</style>

<div style="display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:15px;">
    <form method="get" style="margin:0;">
        <select name="fiscal_year" class="form-control" style="width:auto; font-weight:700;" onchange="this.form.submit()">
            @foreach ($fiscalYears as $fyOption)
                <option value="{{ $fyOption }}" @selected($fyOption === $fy)>{{ $fyOption }}</option>
            @endforeach
        </select>
    </form>
</div>

@if ($decommission)
    @include('reports.deployments._decommissioning')
@else
    <p class="text-muted">{{ trans('admin/deployments/general.decom_future_none') }}</p>
@endif

@stop
