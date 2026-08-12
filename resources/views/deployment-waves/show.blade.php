@extends('layouts/default')

@section('title')
    {{ $wave->name }} @parent
@stop

@section('header_right')
    <a href="{{ route('deployments.forecast', ['fiscal_year' => $wave->fiscal_year]) }}" class="btn btn-sm btn-default"><i class="fas fa-calendar-alt"></i> {{ trans('admin/deployments/general.add_from_forecast') }}</a>
    <a href="{{ route('deployments.storage') }}" class="btn btn-sm btn-default"><i class="fas fa-boxes"></i> {{ trans('admin/deployments/general.storage_title') }}</a>
    <a href="{{ route('deployment-waves.export', $wave) }}" class="btn btn-sm btn-default"><i class="fas fa-download"></i> {{ trans('admin/deployments/general.download') }}</a>
    <a href="{{ route('deployment-waves.edit', $wave) }}" class="btn btn-sm btn-warning"><i class="fas fa-pencil-alt"></i> {{ trans('general.update') }}</a>
    @include('deployment-waves._announce')
@stop

@section('content')

{{-- Wave meta header --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">
            <span class="label" style="background-color: {{ $wave->displayColor() }}; color:#fff;">{{ $wave->typeLabel() }}</span>
            {{ $wave->name }}
        </h3>
        <div class="box-tools pull-right">
            <span class="label label-default">{{ ucfirst($wave->wave_state) }}</span>
        </div>
    </div>
    <div class="box-body">
        {{-- A grid rather than dl-horizontal: its fixed label column is
             narrower than the longest label here, so "Staging Location" and
             friends were truncating to an ellipsis and the values sat miles from
             the words they belonged to. Labels size to content, values follow. --}}
        <dl class="wave-meta">
            <dt>{{ trans('admin/deployments/general.fiscal_year') }}</dt>
            <dd>{{ $wave->fiscal_year ?: '—' }}</dd>
            <dt>{{ trans('admin/deployments/general.arrival_window') }}</dt>
            <dd>{{ optional($wave->arrival_window_start)->toDateString() ?: '?' }} – {{ optional($wave->arrival_window_end)->toDateString() ?: '?' }}</dd>
            <dt>{{ trans('admin/deployments/general.deploy_window') }}</dt>
            <dd>{{ optional($wave->target_start_date)->toDateString() ?: '?' }} – {{ optional($wave->target_end_date)->toDateString() ?: '?' }}</dd>
            <dt>{{ trans('admin/deployments/general.location') }}</dt>
            <dd>{{ $wave->location?->name ?: '—' }}</dd>
            <dt>{{ trans('admin/deployments/general.storage_location') }}</dt>
            <dd>{{ $wave->storageLocation?->name ?: '—' }}</dd>
            <dt>{{ trans('admin/deployments/general.owner') }}</dt>
            <dd>@if ($wave->owner)<a href="{{ route('users.show', $wave->owner) }}">{{ $wave->owner->full_name }}</a>@else — @endif</dd>
            <dt>{{ trans('admin/deployments/general.purchase_order') }}</dt>
            <dd>@if ($wave->purchaseOrder)<a href="{{ route('purchase-orders.show', $wave->purchaseOrder) }}">{{ $wave->purchaseOrder->po_number }}</a>@else — @endif</dd>
            @if ($wave->announced_at)
                <dt>{{ trans('admin/deployments/general.announced_at') }}</dt>
                <dd>{{ \App\Helpers\Helper::getFormattedDateObject($wave->announced_at, 'datetime', false) }}</dd>
            @endif
            <dt>{{ trans('admin/deployments/general.notes') }}</dt>
            <dd>{{ $wave->notes ?: '—' }}</dd>
        </dl>

        <style>
            .wave-meta {
                display: grid;
                grid-template-columns: max-content minmax(0, 1fr);
                column-gap: 18px;
                row-gap: 4px;
                margin: 0;
            }
            .wave-meta dt { text-align: left; font-weight: 700; white-space: nowrap; }
            .wave-meta dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
        </style>
    </div>
</div>

{{-- Arrivals rollup (P2b) --}}
@if ($arrivals['linked'] > 0)
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fas fa-truck"></i> {{ trans('admin/deployments/general.arrivals_title') }}</h3>
        <div class="box-tools pull-right">
            <span class="label label-primary">{{ trans('admin/deployments/general.arrivals_summary', ['received' => $arrivals['received'], 'linked' => $arrivals['linked'], 'in_transit' => $arrivals['in_transit']]) }}</span>
        </div>
    </div>
    <div class="box-body">
        <div class="row">
            <div class="col-sm-3 text-center">
                <span class="label label-success">{{ trans('admin/deployments/general.arrivals_received') }}</span>
                <h3 style="margin:6px 0 0;">{{ $arrivals['received'] }}</h3>
            </div>
            <div class="col-sm-3 text-center">
                <span class="label label-warning">{{ trans('admin/deployments/general.arrivals_in_transit') }}</span>
                <h3 style="margin:6px 0 0;">{{ $arrivals['in_transit'] }}</h3>
            </div>
            <div class="col-sm-3 text-center">
                <span class="label label-default">{{ trans('admin/deployments/general.arrivals_not_ordered') }}</span>
                <h3 style="margin:6px 0 0;">{{ $arrivals['not_ordered'] }}</h3>
            </div>
            <div class="col-sm-3">
                @if (count($arrivals['trackers']))
                    <strong>{{ trans('admin/deployments/general.arrivals_tracking') }}</strong>
                    <ul class="list-unstyled" style="margin-bottom:0;">
                        @foreach ($arrivals['trackers'] as $t)
                            <li><i class="fas fa-barcode text-muted"></i> {{ $t['tracking'] ?: '—' }}@if ($t['carrier']) <span class="text-muted">({{ $t['carrier'] }})</span>@endif</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
@endif

@include('deployment-waves._items')

@stop
