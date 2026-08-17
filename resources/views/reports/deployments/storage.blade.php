@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.storage_title') }} @parent
@stop

@section('content')

{{-- What the page counts, and the one control that changes it. --}}
<div style="display:flex; align-items:flex-start; gap:16px; margin-bottom:12px;">
    <p class="text-muted" style="margin:0; flex:1;">{{ trans('admin/deployments/general.storage_counts_help') }}</p>
    @can('deployments.edit')
        @include('reports.deployments._storage-config')
    @endcan
</div>

{{-- ── The inbox ────────────────────────────────────────────────────
     Staged devices that no room has claimed yet. Full width, first,
     and gone the moment it is empty. Check devices and move them in
     bulk, or drag a row by its handle onto any room below. --}}
@if ($unassignedCount > 0)
<div class="box box-default" id="st-inbox">
    <div class="box-header with-border" style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
        <h3 class="box-title" style="margin:0;">
            <i class="fas fa-inbox text-muted"></i>
            {{ trans('admin/deployments/general.storage_inbox_title', ['count' => $unassignedCount]) }}
        </h3>
        @can('deployments.edit')
            <form method="POST" action="{{ route('deployments.storage.stage-move') }}" id="st-inbox-bulk"
                  class="form-inline" style="display:none; align-items:center; gap:6px; margin-left:auto;">
                @csrf
                <select class="js-data-ajax input-sm" data-endpoint="locations" name="location_id" style="min-width:220px;"
                        data-placeholder="{{ trans('admin/deployments/general.storage_inbox_move_placeholder') }}"></select>
                <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/deployments/general.storage_inbox_move') }} (<span id="st-inbox-count">0</span>)</button>
            </form>
        @endcan
    </div>
    <div class="box-body no-padding">
        <table class="table table-condensed table-striped" style="margin-bottom:0;">
            <thead>
                <tr>
                    <th style="width:26px;"></th>
                    <th style="width:26px;"><input type="checkbox" id="st-inbox-all"></th>
                    <th>{{ trans('general.name') }}</th>
                    <th>{{ trans('general.asset_tag') }}</th>
                    <th>{{ trans('admin/deployments/general.model') }}</th>
                    <th>{{ trans('admin/orders/general.order') }}</th>
                    <th>{{ trans('admin/deployments/general.wave') }}</th>
                    <th>{{ trans('admin/deployments/general.stage') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($unassigned['items'] as $item)
                <tr data-item-id="{{ $item->id }}">
                    <td class="st-handle" title="{{ trans('admin/deployments/general.storage_drag_hint') }}"><i class="fas fa-grip-vertical" aria-hidden="true"></i></td>
                    <td><input type="checkbox" class="st-inbox-check" value="{{ $item->id }}"></td>
                    <td>
                        @if ($item->asset)
                            <a class="js-lightbox" href="{{ route('hardware.show', $item->asset) }}">{{ $item->asset->name ?: '' }}</a>
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
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ── The shelf itself ─────────────────────────────────────────────
     Every deployable-status device checked out to nobody and nothing is
     in storage, whether or not a wave knows about it. One table per
     holding room; drag a device onto another room's table and its
     location follows. Rooms are added and removed right here. --}}
<style>
    .st-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(430px, 100%), 1fr)); gap: 14px; }
    .st-handle { cursor: grab; color: var(--color-fg-muted, #999); text-align: center; user-select: none; }
    .st-handle:active { cursor: grabbing; }
    .st-drop.st-dropover, .st-card.st-dropover { outline: 2px dashed var(--main-theme-color, #3c8dbc); outline-offset: -2px; }
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
            <span class="nw-pop-wrap" style="position:relative; display:inline-block;">
                <button type="button" class="btn btn-sm btn-default nw-pop-toggle" data-pop="st-add-room">
                    <i class="fas fa-plus"></i> {{ trans('admin/deployments/general.shelf_add_location') }}
                </button>
                <div class="nw-pop" id="st-add-room" style="width:300px;">
                    <form method="POST" action="{{ route('deployments.storage.location') }}">
                        @csrf
                        <input type="hidden" name="show" value="1">
                        <label style="display:block; margin-bottom:3px;">{{ trans('admin/deployments/general.storage_config_room') }}</label>
                        <select class="js-data-ajax" data-endpoint="locations" name="location_id" style="width:100%; margin-bottom:10px;"
                                data-placeholder="{{ trans('admin/deployments/general.shelf_add_location') }}"></select>
                        <div class="text-right">
                            <button type="button" class="btn btn-sm btn-default nw-pop-cancel" data-pop="st-add-room">{{ trans('button.cancel') }}</button>
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> {{ trans('button.add') }}</button>
                        </div>
                    </form>
                </div>
            </span>
        @endcan
    </div>
    <div class="box-body">
        @if ($holdingLocations->isEmpty())
            <p class="text-muted" style="margin:0;">{{ trans('admin/deployments/general.shelf_none') }}</p>
        @else
            <div class="st-grid">
                @foreach ($holdingLocations as $room)
                    @php($shelfAssets = $assetsByLocation->get($room->id, collect()))
                    @php($stagedHere = $stagedByLocation->get($room->id, collect()))
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
                            @if ($room->storage_capacity)
                                <span class="label {{ ($shelfAssets->count() + $stagedHere->count()) > $room->storage_capacity ? 'label-danger' : 'label-default' }} st-count" data-cap="{{ $room->storage_capacity }}" style="margin-left:auto;">{{ $shelfAssets->count() + $stagedHere->count() }} / {{ $room->storage_capacity }}</span>
                            @else
                                <span class="label label-default st-count" style="margin-left:auto;">{{ $shelfAssets->count() + $stagedHere->count() }}</span>
                            @endif
                        </div>
                        <div class="box-body no-padding st-scroll">
                            <table class="table table-striped table-condensed" style="margin-bottom:0;">
                                <tbody>
                                {{-- Wave devices physically staged in this
                                     room sit with the rest of it — the room
                                     is one shelf, the chip says why the
                                     device is passing through. --}}
                                @foreach ($stagedHere as $stagedItem)
                                    <tr data-item-id="{{ $stagedItem->id }}">
                                        <td class="st-handle" style="width:24px;" title="{{ trans('admin/deployments/general.storage_drag_hint') }}"><i class="fas fa-grip-vertical" aria-hidden="true"></i></td>
                                        <td style="width:110px;">
                                            @if ($stagedItem->asset)
                                                <a href="{{ route('hardware.show', $stagedItem->asset->id) }}" class="js-lightbox">{{ $stagedItem->asset->asset_tag }}</a>
                                            @else — @endif
                                        </td>
                                        <td>{{ $stagedItem->asset?->name ?: '' }}</td>
                                        <td class="text-muted" style="font-size:12px;">
                                            {{ $stagedItem->asset?->model?->name ?: $stagedItem->model?->name }}
                                            @if ($stagedItem->wave)
                                                <a class="js-lightbox" href="{{ route('deployment-waves.show', $stagedItem->wave) }}"><span class="label" style="background-color: {{ $stagedItem->wave->displayColor() }}; color:#fff; font-size:10px;">{{ $stagedItem->wave->name }}</span></a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                @forelse ($shelfAssets as $shelfAsset)
                                    <tr data-asset-id="{{ $shelfAsset->id }}">
                                        <td class="st-handle" style="width:24px;" title="{{ trans('admin/deployments/general.storage_drag_hint') }}"><i class="fas fa-grip-vertical" aria-hidden="true"></i></td>
                                        <td style="width:110px;"><a href="{{ route('hardware.show', $shelfAsset->id) }}" class="js-lightbox">{{ $shelfAsset->asset_tag }}</a></td>
                                        <td>{{ $shelfAsset->name ?: '' }}</td>
                                        <td class="text-muted" style="font-size:12px;">{{ $shelfAsset->model?->name }}</td>
                                    </tr>
                                @empty
                                    @if ($stagedHere->isEmpty())
                                        <tr class="st-empty"><td colspan="4" class="text-center text-muted">{{ trans('admin/deployments/general.storage_no_devices') }}</td></tr>
                                    @endif
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
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('#st-move-form input[name="_token"]')?.value;

    // Drag by the handle only — the row itself keeps its normal cursor
    // and its links keep working. Mousedown on the grip arms the row.
    document.querySelectorAll('.st-handle').forEach(function (handle) {
        var row = handle.closest('tr');
        handle.addEventListener('mousedown', function () { row.setAttribute('draggable', 'true'); });
        row.addEventListener('dragend', function () { row.removeAttribute('draggable'); });
        row.addEventListener('dragstart', function (e) {
            var payload = row.hasAttribute('data-item-id')
                ? 'item:' + row.getAttribute('data-item-id')
                : 'asset:' + row.getAttribute('data-asset-id');
            e.dataTransfer.setData('text/plain', payload);
            e.dataTransfer.effectAllowed = 'move';
        });
    });

    // Every room — staged box or shelf card — accepts both kinds. The
    // move posts in the background; the row leaves, the counts adjust,
    // and the page never scrolls away from where the person was.
    function moveByFetch(url, body, onDone) {
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (res.ok && res.j.ok) { onDone(); }
            else { alert(res.j.error || 'Move failed'); }
        }).catch(function () { alert('Move failed'); });
    }

    function bump(el, delta) {
        if (! el) { return; }
        var n = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
        var cap = el.getAttribute('data-cap');
        el.textContent = cap ? (n + ' / ' + cap) : n;
    }

    document.querySelectorAll('.st-drop[data-location-id], .st-card[data-location-id]').forEach(function (target) {
        target.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            target.classList.add('st-dropover');
        });
        target.addEventListener('dragleave', function () { target.classList.remove('st-dropover'); });
        target.addEventListener('drop', function (e) {
            e.preventDefault();
            target.classList.remove('st-dropover');
            var payload = (e.dataTransfer.getData('text/plain') || '').split(':');
            if (payload.length !== 2) { return; }
            var locationId = target.getAttribute('data-location-id');
            var row = payload[0] === 'item'
                ? document.querySelector('tr[data-item-id="' + payload[1] + '"]')
                : document.querySelector('tr[data-asset-id="' + payload[1] + '"]');

            if (payload[0] === 'item') {
                moveByFetch(@json(route('deployments.storage.stage-move')), { item_ids: [parseInt(payload[1], 10)], location_id: parseInt(locationId, 10) }, function () {
                    finishMove(row, target, true);
                });
            } else {
                moveByFetch(@json(route('deployments.storage.move')), { asset_id: parseInt(payload[1], 10), location_id: parseInt(locationId, 10) }, function () {
                    finishMove(row, target, false);
                });
            }
        });
    });

    function finishMove(row, target, isItem) {
        if (! row) { return; }
        var sourceCard = row.closest('.st-card');
        if (sourceCard) { bump(sourceCard.querySelector('.st-count'), -1); }

        // Either kind of row lands live in the target card's table — the
        // move must read as done without a reload. Inbox rows carry more
        // columns than a card row, so the device is rebuilt in card shape
        // from what the inbox row already shows.
        var tbody = target.querySelector('tbody');
        if (tbody) {
            var empty = tbody.querySelector('tr.st-empty');
            if (empty) { empty.remove(); }
            if (isItem) {
                // Rebuilt with DOM nodes, never serialized strings — data
                // that came out as text must not go back in as markup.
                var cells = row.querySelectorAll('td');
                var fresh = document.createElement('tr');
                fresh.setAttribute('data-item-id', row.getAttribute('data-item-id'));

                var handleCell = document.createElement('td');
                handleCell.className = 'st-handle';
                handleCell.style.width = '24px';
                var grip = document.createElement('i');
                grip.className = 'fas fa-grip-vertical';
                grip.setAttribute('aria-hidden', 'true');
                handleCell.appendChild(grip);
                fresh.appendChild(handleCell);

                function carry(source, styler) {
                    var cell = document.createElement('td');
                    if (styler) { styler(cell); }
                    if (source) {
                        Array.prototype.forEach.call(source.childNodes, function (node) {
                            cell.appendChild(node.cloneNode(true));
                        });
                    }
                    fresh.appendChild(cell);
                    return cell;
                }

                carry(cells[3], function (c) { c.style.width = '110px'; });
                carry(cells[2]);
                var modelCell = carry(cells[6], function (c) { c.className = 'text-muted'; c.style.fontSize = '12px'; });
                if (cells[4]) {
                    modelCell.insertBefore(document.createTextNode(cells[4].textContent.trim() + ' '), modelCell.firstChild);
                }

                tbody.appendChild(fresh);
                row.remove();
            } else {
                tbody.appendChild(row);
            }
        } else {
            row.remove();
        }
        bump(target.querySelector('.st-count'), 1);

        // An emptied inbox disappears — it is an inbox, not a fixture.
        var inbox = document.getElementById('st-inbox');
        if (inbox && ! inbox.querySelector('tbody tr')) { inbox.remove(); }
        refreshInboxBulk();
    }

    // ── Inbox selection: check devices, move them in bulk. ───────────
    var bulkForm = document.getElementById('st-inbox-bulk');
    var bulkCount = document.getElementById('st-inbox-count');

    function checkedIds() {
        return Array.prototype.map.call(document.querySelectorAll('.st-inbox-check:checked'), function (c) { return c.value; });
    }

    function refreshInboxBulk() {
        if (! bulkForm) { return; }
        var n = checkedIds().length;
        bulkForm.style.display = n > 0 ? 'flex' : 'none';
        if (bulkCount) { bulkCount.textContent = n; }
    }

    document.querySelectorAll('.st-inbox-check').forEach(function (c) {
        c.addEventListener('change', refreshInboxBulk);
    });
    document.getElementById('st-inbox-all')?.addEventListener('change', function () {
        var on = this.checked;
        document.querySelectorAll('.st-inbox-check').forEach(function (c) { c.checked = on; });
        refreshInboxBulk();
    });
    bulkForm?.addEventListener('submit', function () {
        checkedIds().forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'item_ids[]';
            input.value = id;
            bulkForm.appendChild(input);
        });
    });
})();
</script>
@endcan

@stop

