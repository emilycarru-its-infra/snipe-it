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
        <dl class="dl-horizontal">
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
            <dd>{{ $wave->purchaseOrder?->po_number ?: '—' }}</dd>
            <dt>{{ trans('admin/deployments/general.notes') }}</dt>
            <dd>{{ $wave->notes ?: '—' }}</dd>
        </dl>
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

{{-- Items board --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.board_title') }} — {{ $wave->items->count() }} {{ trans('admin/deployments/general.device') }}(s)</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.stage') }}</th>
                    <th>{{ trans('admin/deployments/general.device') }}</th>
                    <th>{{ trans('admin/deployments/general.replaces') }}</th>
                    <th>{{ trans('admin/deployments/general.model') }}</th>
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
                                @if ($item->stage_id && ! $stages->contains('id', $item->stage_id))
                                    <option value="{{ $item->stage_id }}" selected>{{ $item->stage?->name ?: $item->stage_id }} ({{ trans('admin/deployments/general.inactive') }})</option>
                                @endif
                                @foreach ($stages as $st)
                                    <option value="{{ $st->id }}" {{ (int) $item->stage_id === (int) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td>
                        @if ($item->asset)
                            <a href="{{ route('hardware.show', $item->asset) }}">{{ $item->deviceLabel() }}</a>
                        @else
                            {{ $item->deviceLabel() }}
                        @endif
                    </td>
                    <td>
                        @if ($item->replacesAsset)
                            <a href="{{ route('hardware.show', $item->replacesAsset) }}">{{ $item->replacesAsset->asset_tag ?: $item->replacesAsset->name }}</a>
                        @else — @endif
                    </td>
                    <td>{{ $item->model?->name ?: '—' }}</td>
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
                <tr><td colspan="9" class="text-center text-muted">{{ trans('admin/deployments/general.no_items') }}</td></tr>
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
