@extends('layouts/default')

@section('title')
    {{ $wave->name }} @parent
@stop

@section('header_right')
    <a href="{{ route('deployments.forecast', ['fiscal_year' => $wave->fiscal_year]) }}" class="btn btn-sm btn-default"><i class="fas fa-calendar-alt"></i> {{ trans('admin/deployments/general.add_from_forecast') }}</a>
    <a href="{{ route('deployments.storage') }}" class="btn btn-sm btn-default"><i class="fas fa-boxes"></i> {{ trans('admin/deployments/general.storage_title') }}</a>
    <a href="{{ route('deployment-waves.export', $wave) }}" class="btn btn-sm btn-default"><i class="fas fa-download"></i> {{ trans('admin/deployments/general.download') }}</a>
    <a href="{{ route('deployment-waves.edit', $wave) }}" class="btn btn-sm btn-warning"><i class="fas fa-pencil-alt"></i> {{ trans('general.update') }}</a>
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

<div class="box box-default">
    <div class="box-body">
        @include('deployment-waves._announce')
    </div>
</div>

@include('deployment-waves._roster')

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

{{-- Items board --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.board_title') }} — {{ $wave->items->count() }} {{ trans('admin/deployments/general.device') }}(s)</h3>
        @if (($projectedTotal ?? 0) > 0)
            <div class="box-tools pull-right">
                <span class="label label-info" title="{{ trans('admin/deployments/general.projected_cost_help') }}">
                    {{ trans('admin/deployments/general.projected_cost_total', ['total' => number_format($projectedTotal, 2)]) }}
                </span>
            </div>
        @endif
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.stage') }}</th>
                    {{-- Who has it, not what it is: the Device column repeated
                         the Model column, while the one thing this board is for —
                         knowing whose machine is being swapped — was missing. --}}
                    <th>{{ trans('admin/deployments/general.checked_out_to') }}</th>
                    <th>{{ trans('admin/deployments/general.replaces') }}</th>
                    <th>{{ trans('admin/deployments/general.model') }}</th>
                    <th>{{ trans('admin/deployments/general.projected_replacement') }}</th>
                    <th>{{ trans('admin/deployments/general.recipient') }}</th>
                    <th>{{ trans('admin/deployments/general.tech') }}</th>
                    <th>{{ trans('admin/deployments/general.arrival_status') }}</th>
                    <th>{{ trans('admin/deployments/general.target_deploy_date') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($wave->items as $item)
                <tr>
                    <td>
                        <form method="POST" action="{{ route('deployment-items.stage', $item) }}" class="form-inline" style="margin:0;">
                            {{ csrf_field() }}
                            <select name="stage_id" class="form-control input-sm" onchange="this.form.submit()" style="border-left:5px solid {{ $item->stageColor() }};">
                                @foreach ($stages as $st)
                                    <option value="{{ $st->id }}" {{ (int) $item->stage_id === (int) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td>
                        @php
                            // The person on the device being replaced — that is
                            // who the swap is with. Fall back to the incoming
                            // asset's holder once one exists.
                            $holder = $item->replacesAsset?->holderUser() ?? $item->asset?->holderUser() ?? $item->assignedUser;
                            $holderLocation = $item->replacesAsset?->holderLocation() ?? $item->asset?->holderLocation();
                        @endphp
                        @if ($holder)
                            <a href="{{ route('users.show', $holder) }}">{{ $holder->present()->fullName }}</a>
                        @elseif ($holderLocation)
                            <i class="fas fa-location-dot text-muted" aria-hidden="true"></i>
                            <a href="{{ route('locations.show', $holderLocation) }}">{{ $holderLocation->name }}</a>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td>
                        @if ($item->replacesAsset)
                            <a href="{{ route('hardware.show', $item->replacesAsset) }}">{{ $item->replacesAsset->asset_tag ?: $item->replacesAsset->name }}</a>
                        @else — @endif
                    </td>
                    <td>{{ $item->model?->name ?: '—' }}</td>
                    <td>
                        @php($catalog = $item->model?->refreshCatalogItem)
                        @if ($catalog)
                            <span title="{{ $catalog->name }}">${{ number_format($catalog->effectiveCost(), 2) }}</span>
                            @if ($catalog->isEstimate())<span class="label label-default">{{ trans('admin/purchase-orders/general.price_estimate') }}</span>@endif
                        @elseif ($item->replacesAsset?->purchase_cost)
                            <span class="text-muted" title="{{ trans('admin/purchase-orders/general.forecast_basis_original') }}">${{ number_format((float) $item->replacesAsset->purchase_cost, 2) }}</span>
                        @else — @endif
                    </td>
                    <td>@if ($item->assignedUser)<a href="{{ route('users.show', $item->assignedUser) }}">{{ $item->assignedUser->full_name }}</a>@else — @endif</td>
                    <td>{{ $item->assignedTech?->full_name ?: '—' }}</td>
                    <td>
                        @php($badge = $timeline->itemBadge($item))
                        @if ($badge === 'received')
                            <span class="label label-success">{{ trans('admin/deployments/general.arrivals_received') }}</span>
                        @elseif ($badge === 'in_transit')
                            <span class="label label-warning">{{ trans('admin/deployments/general.arrivals_in_transit') }}</span>
                        @else
                            <span class="label label-default">{{ trans('admin/deployments/general.arrivals_not_ordered') }}</span>
                        @endif
                    </td>
                    <td>{{ optional($item->target_deploy_date)->toDateString() ?: '—' }}</td>
                    <td class="text-right">
                        <form method="POST" action="{{ route('deployment-items.destroy', $item) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/deployments/general.item_delete_confirm') }}');">
                            {{ csrf_field() }}@method('DELETE')
                            <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted">{{ trans('admin/deployments/general.no_items') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="box-footer">
        <form method="POST" action="{{ route('deployment-waves.destroy', $wave) }}" style="display:inline-block;" onsubmit="return confirm('{{ trans('admin/deployments/general.delete_confirm') }}');">
            {{ csrf_field() }}@method('DELETE')
            <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> {{ trans('general.delete') }}</button>
        </form>
    </div>
</div>

@stop
