@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.buyout_page_title', ['tag' => $asset->asset_tag]) }} @parent
@stop

{{-- One device's buyout, at a URL you can hand to the lessor thread —
     the same full editor the in-flight table expands to, standing on its
     own page. --}}

@section('header_right')
    <a href="{{ route('deployments.decommissioning') }}" class="btn btn-sm btn-default">{{ trans('admin/deployments/general.decom_nav') }}</a>
    <a href="{{ route('hardware.show', $asset->id) }}" class="btn btn-sm btn-default js-lightbox">{{ trans('admin/deployments/general.buyout_view_device') }}</a>
@stop

@section('content')

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ $asset->asset_tag }}
            <span class="text-muted" style="font-weight:normal;">{{ $asset->model?->name }}</span>
            @if ($asset->serial)
                <span class="text-muted" style="font-weight:normal; font-size:12.5px; font-family:ui-monospace, Menlo, monospace; margin-left:6px;">{{ $asset->serial }}</span>
            @endif
        </h3>
        <div class="box-tools pull-right">
            @if ($row)
                <span class="label label-{{ $row['open'] ? 'primary' : 'default' }}">{{ trans('admin/deployments/general.buyout_status_'.$row['status']) }}</span>
                @if ($row['open'] && $row['waiting_on'])
                    <span class="text-muted" style="font-size:12px; margin-left:6px;">{{ trans($row['waiting_on']) }}</span>
                @endif
            @endif
        </div>
    </div>
    <div class="box-body">
        @if (! $row)
            <p class="text-muted" style="margin:0 0 10px;">{{ trans('admin/deployments/general.buyout_none_for_device') }}</p>
            @can('requestBuyout', \App\Models\Asset::class)
                <form method="POST" action="{{ route('buyouts.open') }}">
                    @csrf
                    <input type="hidden" name="asset_id" value="{{ $asset->id }}">
                    <button type="submit" class="btn btn-primary">{{ trans('admin/deployments/general.buyout_open_button') }}</button>
                </form>
            @endcan
        @else
            <div style="display:flex; flex-wrap:wrap; gap:10px 32px; font-size:13px; margin-bottom:4px;">
                <span><span class="text-muted">{{ trans('admin/deployments/general.buyout_col_buyer') }}:</span> {{ $row['buyer'] ?: '—' }}</span>
                <span><span class="text-muted">{{ trans('admin/deployments/general.buyout_col_lessor') }}:</span> {{ $row['lessor'] ?: '—' }}</span>
                <span><span class="text-muted">{{ trans('admin/deployments/general.buyout_col_age') }}:</span> {{ $row['open'] ? trans('admin/deployments/general.buyout_age_days', ['days' => $row['age']]) : '—' }}</span>
                <span><span class="text-muted">{{ trans('admin/deployments/general.buyout_col_quote') }}:</span> {{ $row['quote_total'] !== null ? '$'.number_format((float) $row['quote_total'], 2) : '—' }}</span>
                <span><span class="text-muted">{{ trans('admin/deployments/general.buyout_col_split') }}:</span> {{ $row['buyer_amount'] !== null ? '$'.number_format((float) $row['buyer_amount'], 2) : '—' }} / {{ $row['ecu_amount'] !== null ? '$'.number_format((float) $row['ecu_amount'], 2) : '—' }}</span>
                @if ($row['invoice_number'])
                    <span><span class="text-muted">{{ trans('admin/deployments/general.buyout_col_invoice') }}:</span> {{ $row['invoice_number'] }}</span>
                @endif
            </div>
        @endif
    </div>
    @if ($row)
        @can('requestBuyout', \App\Models\Asset::class)
            <div class="box-body" style="border-top:1px solid var(--box-border-color, #f4f4f4);">
                @include('reports.deployments._buyout-actions', ['row' => $row])
            </div>
        @endcan
    @endif
</div>

@if ($others->isNotEmpty())
    <div class="box box-default">
        <div class="box-header with-border">
            <h3 class="box-title">{{ trans('admin/deployments/general.buyout_previous', ['count' => $others->count()]) }}</h3>
        </div>
        <div class="box-body">
            <ul class="list-unstyled" style="margin:0;">
                @foreach ($others as $other)
                    <li style="padding:4px 0;">
                        <span class="label label-default">{{ trans('admin/deployments/general.buyout_status_'.$other['status']) }}</span>
                        <span class="text-muted" style="font-size:12.5px; margin-left:8px;">
                            {{ $other['requested_at'] ?: '—' }}
                            @if ($other['quote_total'] !== null) · ${{ number_format((float) $other['quote_total'], 2) }} @endif
                            @if ($other['decline_reason']) · {{ $other['decline_reason'] }} @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

@stop
