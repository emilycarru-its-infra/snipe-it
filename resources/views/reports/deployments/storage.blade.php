@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.storage_title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('reports.deployments') }}" class="btn btn-sm btn-default"><i class="fas fa-arrow-left"></i> {{ trans('admin/deployments/general.dashboard_title') }}</a>
@stop

@section('content')

@php($allRows = array_merge($rows, $unassignedCount > 0 ? [$unassigned] : []))

{{-- What the page counts, and the one control that changes it. The
     configuration sits here rather than behind a location edit and five
     wave edits, which is why none of it had ever been set. --}}
<div style="display:flex; align-items:flex-start; gap:16px; margin-bottom:12px;">
    <p class="text-muted" style="margin:0; flex:1;">{{ trans('admin/deployments/general.storage_counts_help') }}</p>
    @can('deployments.edit')
        @include('reports.deployments._storage-config')
    @endcan
</div>

@if (count($rows) === 0 && $unassignedCount === 0)
    <div class="callout callout-info">
        <i class="fas fa-info-circle"></i> {{ trans('admin/deployments/general.storage_no_locations') }}
    </div>
@endif

<div class="row">
    @foreach ($allRows as $row)
        <div class="col-md-6">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">
                        <i class="fas fa-warehouse text-muted"></i>
                        @if ($row['location'])
                            <a href="{{ route('locations.show', $row['location']) }}">{{ $row['name'] }}</a>
                        @else
                            {{ $row['name'] }}
                        @endif
                    </h3>
                    <div class="box-tools pull-right">
                        @if ($row['capacity'])
                            <span class="label {{ $row['over'] > 0 ? 'label-danger' : 'label-default' }}">
                                {{ $row['count'] }} / {{ $row['capacity'] }}
                            </span>
                        @else
                            <span class="label label-default">{{ $row['count'] }} {{ trans('admin/deployments/general.storage_staged') }}</span>
                        @endif
                    </div>
                </div>
                <div class="box-body">
                    @if ($row['capacity'])
                        <div class="progress" style="margin-bottom:6px;">
                            <div class="progress-bar {{ $row['tone'] }}" role="progressbar"
                                 style="width: {{ $row['pct'] }}%; min-width:2em;"
                                 aria-valuenow="{{ $row['count'] }}" aria-valuemin="0" aria-valuemax="{{ $row['capacity'] }}">
                                {{ $row['pct'] }}%
                            </div>
                        </div>
                        @if ($row['over'] > 0)
                            <p class="text-danger" style="margin-bottom:6px;"><i class="fas fa-exclamation-triangle"></i> {{ trans('admin/deployments/general.storage_over_capacity', ['count' => $row['over']]) }}</p>
                        @endif
                    @else
                        <p class="text-muted" style="margin-bottom:6px;">{{ trans('admin/deployments/general.storage_uncapped') }}</p>
                    @endif

                    {{-- Waves staging here --}}
                    @if ($row['location'] && $wavesByStorage->has($row['location']->id))
                        <p style="margin-bottom:4px;"><strong>{{ trans('admin/deployments/general.storage_waves_here') }}</strong></p>
                        <p>
                            @foreach ($wavesByStorage->get($row['location']->id) as $w)
                                <a class="js-lightbox" href="{{ route('deployment-waves.show', $w) }}"><span class="label" style="background-color: {{ $w->displayColor() }}; color:#fff;">{{ $w->name }}</span></a>
                            @endforeach
                        </p>
                    @endif

                    {{-- Device list --}}
                    <table class="table table-condensed table-striped" style="margin-bottom:0;">
                        <thead>
                            <tr>
                                <th>{{ trans('general.name') }}</th>
                                <th>{{ trans('general.asset_tag') }}</th>
                                <th>{{ trans('admin/deployments/general.model') }}</th>
                                <th>{{ trans('admin/orders/general.order') }}</th>
                                <th>{{ trans('admin/deployments/general.wave') }}</th>
                                <th>{{ trans('admin/deployments/general.stage') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($row['items'] as $item)
                            <tr>
                                {{-- The name is the name — an unnamed device reads
                                     as blank here, with the tag column carrying the
                                     identity. --}}
                                <td>
                                    @if ($item->asset)
                                        <a class="js-lightbox" href="{{ route('hardware.show', $item->asset) }}">{{ $item->asset->name ?: '' }}</a>
                                    @else
                                        {{ $item->asset?->name ?: '' }}
                                    @endif
                                </td>
                                <td>
                                    @if ($item->asset)
                                        <a class="js-lightbox" href="{{ route('hardware.show', $item->asset) }}">{{ $item->asset->asset_tag }}</a>
                                    @else — @endif
                                </td>
                                <td>{{ $item->asset?->model?->name ?: $item->model?->name ?: '—' }}</td>
                                <td>
                                    @if ($item->orderItem?->order)
                                        <a class="js-lightbox" href="{{ route('orders.show', $item->orderItem->order_id) }}">{{ $item->orderItem->order->order_number }}</a>
                                    @else — @endif
                                </td>
                                <td>@if ($item->wave)<a class="js-lightbox" href="{{ route('deployment-waves.show', $item->wave) }}">{{ $item->wave->name }}</a>@else — @endif</td>
                                <td><span class="label" style="background-color: {{ $item->stageColor() }}; color:#fff;">{{ $item->stageLabel() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">{{ trans('admin/deployments/general.storage_no_devices') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- ── The shelf itself ─────────────────────────────────────────────
     Every deployable-status device checked out to nobody and nothing is
     in storage, whether or not a wave knows about it. One table per
     holding room; drag a device onto another room's table and its
     location follows. Rooms are added and removed right here. --}}
<style>
    .st-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(430px, 100%), 1fr)); gap: 14px; }
    .st-card tbody tr[draggable="true"] { cursor: grab; }
    .st-card.st-dropover { outline: 2px dashed var(--main-theme-color, #3c8dbc); outline-offset: -2px; }
    .st-card .box-header { display: flex; align-items: center; gap: 8px; }
    .st-remove { background: none; border: 0; opacity: .5; font-size: 14px; padding: 0 4px; }
    .st-remove:hover { opacity: 1; }
    .st-scroll { max-height: 340px; overflow: auto; }
</style>

<div class="box box-default" id="shelf">
    <div class="box-header with-border" style="display:flex; align-items:center; flex-wrap:wrap; gap:12px;">
        <h3 class="box-title" style="margin:0;">{{ trans('admin/deployments/general.shelf_title', ['count' => $holdingLocations->isEmpty() ? 0 : $assetsByLocation->flatten(1)->count()]) }}</h3>
        <span class="text-muted" style="font-size:12px;">{{ trans('admin/deployments/general.shelf_hint') }}</span>
        @can('deployments.edit')
            <form method="POST" action="{{ route('deployments.storage.location') }}" class="form-inline" style="display:flex; align-items:center; gap:6px; margin:0;">
                @csrf
                <input type="hidden" name="show" value="1">
                <select class="js-data-ajax input-sm" data-endpoint="locations" name="location_id" style="min-width:220px;"
                        data-placeholder="{{ trans('admin/deployments/general.shelf_add_location') }}"></select>
                <button type="submit" class="btn btn-sm btn-default"><i class="fas fa-plus"></i> {{ trans('admin/deployments/general.shelf_add_location') }}</button>
            </form>
        @endcan
    </div>
    <div class="box-body">
        @if ($holdingLocations->isEmpty())
            <p class="text-muted" style="margin:0;">{{ trans('admin/deployments/general.shelf_none') }}</p>
        @else
            <div class="st-grid">
                @foreach ($holdingLocations as $room)
                    @php($shelfAssets = $assetsByLocation->get($room->id, collect()))
                    <div class="box box-default st-card" data-location-id="{{ $room->id }}" style="margin-bottom:0;">
                        <div class="box-header with-border">
                            @can('deployments.edit')
                                @if ($room->show_in_storage)
                                    <form method="POST" action="{{ route('deployments.storage.location') }}" style="margin:0;"
                                          onsubmit="return confirm({!! json_encode(trans('admin/deployments/general.shelf_remove_confirm', ['name' => $room->name])) !!});">
                                        @csrf
                                        <input type="hidden" name="show" value="0">
                                        <input type="hidden" name="location_id" value="{{ $room->id }}">
                                        <button type="submit" class="st-remove" title="{{ trans('admin/deployments/general.shelf_remove_location') }}">&times;</button>
                                    </form>
                                @endif
                            @endcan
                            <h3 class="box-title" style="font-size:14px;">
                                <a href="{{ route('locations.show', $room->id) }}" class="js-lightbox">{{ $room->name }}</a>
                            </h3>
                            <span class="label label-default" style="margin-left:auto;">{{ $shelfAssets->count() }}</span>
                        </div>
                        <div class="box-body no-padding st-scroll">
                            <table class="table table-striped table-condensed" style="margin-bottom:0;">
                                <tbody>
                                @forelse ($shelfAssets as $shelfAsset)
                                    <tr draggable="true" data-asset-id="{{ $shelfAsset->id }}">
                                        <td style="width:110px;"><a href="{{ route('hardware.show', $shelfAsset->id) }}" class="js-lightbox">{{ $shelfAsset->asset_tag }}</a></td>
                                        <td>{{ $shelfAsset->name ?: '' }}</td>
                                        <td class="text-muted" style="font-size:12px;">{{ $shelfAsset->model?->name }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted">{{ trans('admin/deployments/general.storage_no_devices') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@can('deployments.edit')
<form id="st-move-form" method="POST" action="{{ route('deployments.storage.move') }}" style="display:none;">
    @csrf
    <input type="hidden" name="asset_id" value="">
    <input type="hidden" name="location_id" value="">
</form>
<script nonce="{{ csrf_token() }}">
(function () {
    var form = document.getElementById('st-move-form');
    if (! form) { return; }

    // Same drag contract the board uses: the row carries the id, the
    // card is the target, the drop submits a plain form.
    Array.prototype.forEach.call(document.querySelectorAll('.st-card tbody tr[draggable="true"]'), function (row) {
        row.addEventListener('dragstart', function (e) {
            e.dataTransfer.setData('text/plain', row.getAttribute('data-asset-id'));
            e.dataTransfer.effectAllowed = 'move';
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.st-card'), function (card) {
        card.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            card.classList.add('st-dropover');
        });
        card.addEventListener('dragleave', function () { card.classList.remove('st-dropover'); });
        card.addEventListener('drop', function (e) {
            e.preventDefault();
            card.classList.remove('st-dropover');
            var assetId = e.dataTransfer.getData('text/plain');
            if (! assetId) { return; }
            form.querySelector('input[name="asset_id"]').value = assetId;
            form.querySelector('input[name="location_id"]').value = card.getAttribute('data-location-id');
            form.submit();
        });
    });
})();
</script>
@endcan

@stop

