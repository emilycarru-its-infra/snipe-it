@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.dashboard_title') }} @parent
@stop

@section('header_right')
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default"><i class="fas fa-download"></i> {{ trans('admin/deployments/general.download') }}</a>
@stop

@section('content')
<div class="row"><div class="col-md-12">

{{-- Filters + the module's doors, on one visible row. --}}
<div style="display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:15px;">
    <form method="GET" action="{{ route('reports.deployments') }}" style="display:flex; align-items:center; gap:8px; margin:0;">
        <label style="margin:0;">{{ trans('admin/deployments/general.filter_fiscal_year') }}</label>
        <select name="fiscal_year" class="form-control" style="width:auto;" onchange="this.form.submit()">
            @foreach ($fiscalYears as $y)
                <option value="{{ $y }}" {{ (string) $fy === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
        </select>
        <label style="margin:0 0 0 6px;">{{ trans('admin/deployments/general.filter_type') }}</label>
        <select name="deployment_type" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <option value="">{{ trans('admin/deployments/general.all_types') }}</option>
            @foreach ($types as $t)
                <option value="{{ $t->slug }}" {{ $typeFilter === $t->slug ? 'selected' : '' }}>{{ $t->name }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('deployments.planning', ['fiscal_year' => $fy]) }}" class="btn btn-default"><i class="fas fa-calendar-alt"></i> {{ trans('admin/deployments/general.forecast') }}</a>
    <a href="{{ route('deployments.blackouts.index') }}" class="btn btn-default"><i class="fas fa-user-clock"></i> {{ trans('admin/deployments/general.blackouts_button') }}</a>
    @can('deployments.edit')
        @include('reports.deployments._new-wave-popover', ['popoverId' => 'dp-new-wave', 'fy' => $fy, 'types' => $types])
    @endcan
</div>

{{-- Device-flow chevron rail. Counts are counts of device rows in the
     unified table below, so chevrons and table can never disagree. A
     chevron click filters the device rows; clicking the selected chevron
     again clears the filter. --}}
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
    .dp-chev:hover, .dp-chev:focus { text-decoration: none; background: color-mix(in srgb, var(--dp-c) 20%, var(--box-bg, #fff)); }
    .dp-chev.selected { background: var(--dp-c); }
    .dp-chev.dp-dropover { background: color-mix(in srgb, var(--dp-c) 45%, var(--box-bg, #fff)); }
    .dp-chev .dp-stage { font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--dp-c); }
    .dp-chev .dp-big { font-size: 20px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; color: var(--color-fg, #333); }
    .dp-chev.selected .dp-stage, .dp-chev.selected .dp-big { color: #fff; }

    .dp-scroll { max-height: 72vh; overflow: auto; }
    .dp-scroll table { border-collapse: separate; border-spacing: 0; }
    .dp-scroll thead th {
        position: sticky; top: 0; z-index: 2;
        background: var(--box-bg, #fff);
        box-shadow: 0 1px 0 var(--box-border-color, #f4f4f4);
    }
    #dp-rows tr[draggable="true"] { cursor: grab; }

    /* ── The unified table: wave rows carrying their own gantt, device
         rows nested beneath. One shared gantt width means every bar sits
         on the same time axis and the right edge reads as a column. ── */
    :root { --dp-gantt-w: 360px; }
    .dp-wave-row td {
        background: color-mix(in srgb, var(--main-theme-color, #3c8dbc) 8%, var(--box-bg, #fff));
        border-top: 2px solid var(--box-border-color, #e3e3e3);
        cursor: grab;
    }
    .dp-wave-inner { display: flex; align-items: center; gap: 10px; min-height: 26px; }
    .dp-wave-inner .dp-group-check { margin: 0; flex: 0 0 auto; }
    .dp-wave-meta { font-size: 12px; opacity: .75; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dp-group-toggle {
        background: none; border: 0; padding: 0 2px 0 0; color: inherit;
        cursor: pointer; font-size: 11px; opacity: .75;
    }
    .dp-group-toggle:hover { opacity: 1; }

    .dp-gantt {
        position: relative; flex: 0 0 var(--dp-gantt-w); width: var(--dp-gantt-w);
        height: 20px; margin-left: auto; border-radius: 4px;
        background: color-mix(in srgb, var(--color-fg, #333) 5%, transparent);
        overflow: hidden;
    }
    .dp-gantt-bar { position: absolute; height: 7px; border-radius: 3px; min-width: 3px; }
    .dp-gantt-bar.arrival { top: 2px; }
    .dp-gantt-bar.deploy { bottom: 2px; }
    .dp-gantt-today { position: absolute; top: 0; bottom: 0; width: 1px; background: #e74c3c; opacity: .8; }
    .dp-gantt-empty { font-size: 10.5px; opacity: .5; line-height: 20px; padding-left: 8px; }
    .dp-scale-wrap { display: flex; align-items: center; gap: 14px; justify-content: flex-end; padding: 6px 8px 2px; }
    .dp-scale { position: relative; width: var(--dp-gantt-w); height: 15px; }
    .dp-scale-month {
        position: absolute; top: 0; font-size: 10px; text-transform: uppercase;
        letter-spacing: .05em; opacity: .6; border-left: 1px solid var(--box-border-color, #ddd);
        padding-left: 4px; white-space: nowrap;
    }
    .dp-legend { font-size: 10.5px; opacity: .7; display: flex; gap: 10px; align-items: center; }
    .dp-legend i { display: inline-block; width: 14px; height: 6px; border-radius: 3px; margin-right: 4px; vertical-align: middle; }

    /* View modes. Waves folds the device rows away. Timeline takes the
       table over: a fixed name rail, the bars stretched across the rest
       with their date labels shown, the device header gone — a real
       gantt, not a narrower table. */
    #devices-flow.dp-mode-waves tr.dp-item-row,
    #devices-flow.dp-mode-storage tr.dp-item-row,
    #devices-flow.dp-mode-timeline tr.dp-item-row { display: none !important; }
    .dp-wave-storage { display: none; font-size: 12.5px; }
    #devices-flow.dp-mode-storage .dp-wave-storage { display: inline; }
    #devices-flow.dp-mode-storage .dp-wave-meta,
    #devices-flow.dp-mode-storage .dp-gantt { display: none; }
    #devices-flow.dp-mode-timeline .dp-wave-meta { display: none; }
    #devices-flow.dp-mode-timeline #dp-table thead { display: none; }
    .dp-wave-name { display: flex; align-items: center; gap: 10px; min-width: 0; }
    #devices-flow.dp-mode-timeline .dp-wave-name { flex: 0 0 250px; overflow: hidden; }
    #devices-flow.dp-mode-timeline .dp-gantt { flex: 1 1 auto; width: auto; height: 26px; }
    .dp-gantt-lab { display: none; position: absolute; font-size: 10px; opacity: .75; top: 50%; transform: translateY(-50%); white-space: nowrap; padding-left: 5px; }
    #devices-flow.dp-mode-timeline .dp-gantt-lab { display: block; }
    #devices-flow.dp-mode-timeline .dp-scale-wrap { padding-left: calc(250px + 28px); }
    #devices-flow.dp-mode-timeline .dp-scale { flex: 1 1 auto; width: auto; }
    #devices-flow.dp-mode-timeline .dp-legend { order: 2; margin-left: 10px; }

    .dp-bulkbar { display: none; padding: 8px 10px; border-bottom: 1px solid var(--box-border-color, #f4f4f4); }
    .dp-bulkbar.active { display: block; }
    .dp-bulkbar .form-control { display: inline-block; width: auto; vertical-align: middle; }
</style>
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.rail_title') }}</h3>
        <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ trans('admin/deployments/general.rail_hint') }}</span>
    </div>
    <div class="box-body">
        <div class="dp-rail-scroll">
            <div class="dp-rail" id="dp-rail">
                @foreach ($stageRail as $rs)
                    <a class="dp-chev" data-stage="{{ $rs['slug'] }}" data-stage-id="{{ $rs['id'] }}" style="--dp-c: {{ $rs['color'] }}" href="#devices-flow">
                        <div class="dp-stage">{{ $rs['name'] }}</div>
                        <div class="dp-big">{{ $rs['count'] }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- The one table. Waves are the top-level rows — each carrying its
     meta and its own timeline bars on a shared axis — with the wave's
     devices nested beneath. The Waves / Timeline views are the same
     table with the device rows (and then the meta) folded away, so
     there is exactly one place where a wave exists on this page. --}}
@php
    $rowsByWave = collect($deviceRows)->groupBy(fn ($r) => $r['wave_id'] ?? 0);
    $tlByWave = collect($timeline['rows'] ?? [])->keyBy(fn ($r) => $r['wave']->id);
    $todayPct = $timeline['today_pct'] ?? null;
    $hasScale = ! empty($timeline['months']);
    $looseRows = $rowsByWave->get(0, collect());
@endphp
<div class="box box-default" id="devices-flow">
    <div class="box-header with-border" style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
        <h3 class="box-title" id="dp-title" style="margin:0;">{{ trans('admin/deployments/general.unified_title', ['waves' => $waves->count(), 'count' => count($deviceRows), 'fy' => $fy]) }}</h3>
        <span class="btn-group" id="dp-view-btns">
            <button type="button" class="btn btn-sm btn-default" data-view="timeline">{{ trans('admin/deployments/general.view_timeline') }}</button>
            <button type="button" class="btn btn-sm btn-default" data-view="waves">{{ trans('admin/deployments/general.view_waves') }}</button>
            <button type="button" class="btn btn-sm btn-default" data-view="storage">{{ trans('admin/deployments/general.view_storage') }}</button>
        </span>
    </div>
    {{-- Bulk action bar: appears once anything is checked. Grouping is the
         everyday action; stage moves hide behind Manual override — the
         stages follow order and checkout facts on their own. --}}
    <div class="dp-bulkbar" id="dp-bulkbar">
        <strong id="dp-sel-count"></strong>
        <span id="dp-move-wrap" style="margin-left:12px; display:none;">
            <input type="text" id="dp-group-input" class="form-control input-sm" style="width:160px;" placeholder="{{ trans('admin/deployments/general.flow_set_group') }}">
            <button type="button" class="btn btn-xs btn-default" id="dp-group-apply">{{ trans('admin/deployments/general.flow_set_group') }}</button>
            <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ trans('admin/deployments/general.flow_auto_hint') }}</span>
            <button type="button" class="btn btn-xs btn-link" id="dp-manual-toggle">{{ trans('admin/deployments/general.flow_manual_override') }}</button>
            <span id="dp-manual-stages" style="display:none; margin-left:4px;">
                @foreach ($stages as $stage)
                    <button type="button" class="btn btn-xs btn-default dp-move-btn" data-stage-id="{{ $stage->id }}"
                            style="border-color: {{ $stage->color ?: '#bdc3c7' }};">
                        {{ $stage->name }}
                    </button>
                @endforeach
                <span class="text-muted" style="font-size:12px; margin-left:8px;">{{ trans('admin/deployments/general.flow_gate_hint') }}</span>
            </span>
        </span>
    </div>

    {{-- The shared time axis: month ticks and the legend, aligned over
         the gantt column every wave row draws its bars in. --}}
    @if ($hasScale)
        <div class="dp-scale-wrap">
            <span class="dp-legend">
                <span><i style="background:#2f7fb8;"></i>{{ trans('admin/deployments/general.timeline_legend_arrival') }}</span>
                <span><i style="background:#9ec7e3;"></i>{{ trans('admin/deployments/general.timeline_legend_deploy') }}</span>
            </span>
            <div class="dp-scale">
                @foreach ($timeline['months'] as $month)
                    <span class="dp-scale-month" style="left: {{ $month['offsetPct'] }}%;">{{ $month['label'] }}</span>
                @endforeach
                @if ($todayPct !== null)
                    <span class="dp-gantt-today" style="left: {{ $todayPct }}%;" title="{{ trans('admin/deployments/general.timeline_today') }}"></span>
                @endif
            </div>
        </div>
    @endif

    <div class="box-body no-padding dp-scroll">
        <table class="table table-hover table-condensed" style="margin-bottom:0;" id="dp-table">
            <thead>
                <tr>
                    <th style="width:28px;"><input type="checkbox" id="dp-select-all"></th>
                    <th>{{ trans('admin/deployments/general.device') }}</th>
                    <th>{{ trans('admin/deployments/general.model') }}</th>
                    <th>{{ trans('admin/deployments/general.stage') }}</th>
                    <th>{{ trans('admin/deployments/general.refresh_reason') }}</th>
                    <th>{{ trans('admin/deployments/general.source_date') }}</th>
                    <th>{{ trans('general.status') }}</th>
                    <th>{{ trans('admin/deployments/general.location') }}</th>
                </tr>
            </thead>
            <tbody id="dp-rows">
            @forelse ($waves as $wave)
                @php
                    $waveRows = $rowsByWave->get($wave->id, collect());
                    $tl = $tlByWave->get($wave->id);
                @endphp
                <tr class="dp-wave-row" data-wave-id="{{ $wave->id }}" draggable="true">
                    <td colspan="8">
                        <div class="dp-wave-inner">
                            <span class="dp-wave-name">
                                <button type="button" class="dp-group-toggle" aria-expanded="true" data-wave-id="{{ $wave->id }}">
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                </button>
                                <input type="checkbox" class="dp-group-check">
                                <a class="js-lightbox" href="{{ route('deployment-waves.show', $wave) }}"><span class="label" style="background-color: {{ $wave->displayColor() }}; color:#fff;">{{ $wave->name }}</span></a>
                            </span>
                            <span class="dp-wave-storage">
                                {{ trans('admin/deployments/general.storage_location') }}:
                                @if ($wave->storageLocation)
                                    <a href="{{ route('locations.show', $wave->storage_location_id) }}" class="js-lightbox">{{ $wave->storageLocation->name }}</a>
                                @else — @endif
                                <span class="text-muted" style="margin: 0 6px;">·</span>
                                {{ trans('admin/deployments/general.location') }}:
                                @if ($wave->location)
                                    <a href="{{ route('locations.show', $wave->location_id) }}" class="js-lightbox">{{ $wave->location->name }}</a>
                                @else — @endif
                            </span>
                            <span class="dp-wave-meta">
                                {{ $wave->typeLabel() }} · {{ ucfirst($wave->wave_state) }} · {{ trans_choice('general.countable.assets', $waveRows->count(), ['count' => $waveRows->count()]) }}
                                @if ($wave->arrival_window_start || $wave->target_start_date)
                                    · {{ optional($wave->arrival_window_start)->format('M j') ?: '—' }} → {{ optional($wave->target_start_date)->format('M j') ?: '—' }}
                                @endif
                                @if ($wave->owner)
                                    · {{ $wave->owner->full_name }}
                                @endif
                            </span>
                            @if ($tl && count($tl['collisions'] ?? []))
                                <span class="text-warning" title="{{ trans('admin/deployments/general.timeline_collision_tooltip') }}"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                            @endif
                            <span class="dp-gantt">
                                @if ($tl && ($tl['arrival'] || $tl['deploy']))
                                    @if ($tl['arrival'])
                                        <span class="dp-gantt-bar arrival" style="left: {{ $tl['arrival']['offsetPct'] }}%; width: {{ $tl['arrival']['widthPct'] }}%; background:#2f7fb8;" title="{{ $tl['arrival']['label'] }}"></span>
                                    @endif
                                    @if ($tl['deploy'])
                                        <span class="dp-gantt-bar deploy" style="left: {{ $tl['deploy']['offsetPct'] }}%; width: {{ $tl['deploy']['widthPct'] }}%; background:#9ec7e3;" title="{{ $tl['deploy']['label'] }}"></span>
                                    @endif
                                    @php($labBar = $tl['deploy'] ?: $tl['arrival'])
                                    <span class="dp-gantt-lab" style="left: {{ min($labBar['offsetPct'] + $labBar['widthPct'], 80) }}%;">{{ trim(($tl['arrival']['label'] ?? '').($tl['arrival'] && $tl['deploy'] ? ' · ' : '').($tl['deploy']['label'] ?? '')) }}</span>
                                    @if ($todayPct !== null)
                                        <span class="dp-gantt-today" style="left: {{ $todayPct }}%;"></span>
                                    @endif
                                @else
                                    <span class="dp-gantt-empty">{{ trans('admin/deployments/general.timeline_no_dates') }}</span>
                                @endif
                            </span>
                        </div>
                    </td>
                </tr>
                @foreach ($waveRows as $row)
                    <tr class="dp-item-row"
                        data-wave-id="{{ $wave->id }}"
                        data-stage="{{ $row['stage_slug'] }}"
                        @if ($row['item_id']) data-item-id="{{ $row['item_id'] }}" @endif>
                        <td>
                            @if ($row['item_id'])
                                <input type="checkbox" class="dp-check">
                            @endif
                        </td>
                        <td>
                            @if ($row['device_url'])
                                <a href="{{ $row['device_url'] }}" class="js-lightbox">{{ $row['device'] }}</a>
                            @else
                                {{ $row['device'] }}
                            @endif
                            @if ($row['group'])
                                <span class="text-muted" style="font-size:11px;">· {{ $row['group'] }}</span>
                            @endif
                        </td>
                        <td>{{ $row['model'] }}</td>
                        <td><span class="label" style="background-color: {{ $row['stage_color'] }}; color:#fff;">{{ $row['stage_name'] }}</span></td>
                        <td>{{ $row['context'] }}</td>
                        <td>{{ $row['due'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['location'] }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="8" class="text-center text-muted">{{ trans('admin/deployments/general.no_waves') }}</td></tr>
            @endforelse

            {{-- Past years reconstruct devices with no wave to nest under;
                 they trail the plan as their own history block. --}}
            @if ($looseRows->isNotEmpty())
                <tr class="dp-wave-row">
                    <td colspan="8"><div class="dp-wave-inner">
                        <span style="font-weight:700; font-size:12px; text-transform:uppercase; letter-spacing:.05em;">{{ trans('admin/deployments/general.flow_history_chip') }} · {{ $looseRows->count() }}</span>
                    </div></td>
                </tr>
                @foreach ($looseRows as $row)
                    <tr class="dp-item-row" data-stage="{{ $row['stage_slug'] }}">
                        <td></td>
                        <td>
                            @if ($row['device_url'])
                                <a href="{{ $row['device_url'] }}" class="js-lightbox">{{ $row['device'] }}</a>
                            @else
                                {{ $row['device'] }}
                            @endif
                        </td>
                        <td>{{ $row['model'] }}</td>
                        <td><span class="label" style="background-color: {{ $row['stage_color'] }}; color:#fff;">{{ $row['stage_name'] }}</span></td>
                        <td>{{ $row['context'] }}</td>
                        <td>{{ $row['due'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['location'] }}</td>
                    </tr>
                @endforeach
            @endif
            </tbody>
        </table>
    </div>
</div>

<form id="dp-stage-form" method="POST" action="{{ route('deployment-items.bulk-stage') }}" style="display:none;">
    @csrf
    <input type="hidden" name="stage_id" value="">
    <span id="dp-stage-ids"></span>
</form>
<form id="dp-group-form" method="POST" action="{{ route('deployment-items.bulk-group') }}" style="display:none;">
    @csrf
    <input type="hidden" name="group_label" value="">
    <span id="dp-group-ids"></span>
</form>

{{-- Decommissioning lives at /deployments/decommissioning — one page,
     not a copy at the bottom of the board. Agreements likewise live at
     /procurement/agreements. --}}

<script nonce="{{ csrf_token() }}">
(function () {
    var tbody = document.getElementById('dp-rows');
    if (!tbody) { return; }

    var table = document.getElementById('dp-table');
    var itemRows = Array.prototype.slice.call(tbody.querySelectorAll('tr.dp-item-row'));
    var stageFilter = '';
    var collapsedWaves = {};

    function applyVisibility() {
        itemRows.forEach(function (r) {
            var stageOk = !stageFilter || r.getAttribute('data-stage') === stageFilter;
            var open = collapsedWaves[r.getAttribute('data-wave-id') || ''] !== true;
            r.style.display = (stageOk && open) ? '' : 'none';
        });
    }

    // ── Rail chevrons: same filters they have always been. ───────────
    Array.prototype.forEach.call(document.querySelectorAll('#dp-rail .dp-chev'), function (chev) {
        chev.addEventListener('click', function (e) {
            e.preventDefault();
            var slug = chev.getAttribute('data-stage');
            var wasSelected = chev.classList.contains('selected');
            Array.prototype.forEach.call(document.querySelectorAll('#dp-rail .dp-chev'), function (c) { c.classList.remove('selected'); });
            stageFilter = wasSelected ? '' : slug;
            if (!wasSelected) { chev.classList.add('selected'); }
            applyVisibility();
        });
    });

    // ── View toggles: click folds the table to that view, clicking the
    //    active one unfolds back to everything. ─────────────────────────
    var box = document.getElementById('devices-flow');
    Array.prototype.forEach.call(document.querySelectorAll('#dp-view-btns button'), function (btn) {
        btn.addEventListener('click', function () {
            var view = btn.getAttribute('data-view');
            var wasActive = btn.classList.contains('active');
            Array.prototype.forEach.call(document.querySelectorAll('#dp-view-btns button'), function (b) { b.classList.remove('active'); });
            box.classList.remove('dp-mode-waves', 'dp-mode-timeline', 'dp-mode-storage');
            if (! wasActive) {
                btn.classList.add('active');
                box.classList.add('dp-mode-' + view);
            }
        });
    });

    // ── Per-wave collapse. ───────────────────────────────────────────
    Array.prototype.forEach.call(document.querySelectorAll('.dp-group-toggle'), function (chev) {
        chev.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = chev.getAttribute('data-wave-id');
            var nowCollapsed = collapsedWaves[id] !== true;
            collapsedWaves[id] = nowCollapsed;
            chev.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
            chev.innerHTML = '<i class="fa-solid ' + (nowCollapsed ? 'fa-chevron-right' : 'fa-chevron-down') + '" aria-hidden="true"></i>';
            applyVisibility();
            refreshBulkbar();
        });
    });

    // ── Selection + bulk actions. ────────────────────────────────────
    var bulkbar = document.getElementById('dp-bulkbar');
    var selCount = document.getElementById('dp-sel-count');
    var moveWrap = document.getElementById('dp-move-wrap');

    function selected() {
        return itemRows.filter(function (r) {
            var box = r.querySelector('.dp-check');
            return box && box.checked && r.style.display !== 'none';
        });
    }

    function refreshBulkbar() {
        var sel = selected();
        bulkbar.classList.toggle('active', sel.length > 0);
        selCount.textContent = @json(trans('admin/deployments/general.flow_selected', ['count' => '__N__'])).replace('__N__', sel.length);
        moveWrap.style.display = sel.length > 0 ? '' : 'none';
    }

    var manualToggle = document.getElementById('dp-manual-toggle');
    manualToggle?.addEventListener('click', function () {
        var stagesEl = document.getElementById('dp-manual-stages');
        stagesEl.style.display = stagesEl.style.display === 'none' ? '' : 'none';
    });

    tbody.addEventListener('change', function (e) {
        if (e.target.classList.contains('dp-check')) { refreshBulkbar(); }
    });

    // A wave's checkbox selects every visible device beneath it.
    Array.prototype.forEach.call(document.querySelectorAll('.dp-wave-row .dp-group-check'), function (check) {
        check.addEventListener('change', function () {
            var waveId = check.closest('tr').getAttribute('data-wave-id');
            itemRows.forEach(function (r) {
                if (r.getAttribute('data-wave-id') === waveId && r.style.display !== 'none') {
                    var box = r.querySelector('.dp-check');
                    if (box) { box.checked = check.checked; }
                }
            });
            refreshBulkbar();
        });
    });

    document.getElementById('dp-select-all').addEventListener('change', function () {
        var check = this.checked;
        itemRows.forEach(function (r) {
            var box = r.querySelector('.dp-check');
            if (box && r.style.display !== 'none') { box.checked = check; }
        });
        refreshBulkbar();
    });

    function fillIds(containerId, name, values) {
        var container = document.getElementById(containerId);
        container.innerHTML = '';
        values.forEach(function (v) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = v;
            container.appendChild(input);
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.dp-move-btn'), function (btn) {
        btn.addEventListener('click', function () {
            var ids = selected().map(function (r) { return r.getAttribute('data-item-id'); }).filter(Boolean);
            if (!ids.length) { return; }
            var form = document.getElementById('dp-stage-form');
            form.querySelector('input[name="stage_id"]').value = btn.getAttribute('data-stage-id');
            fillIds('dp-stage-ids', 'item_ids[]', ids);
            form.submit();
        });
    });

    document.getElementById('dp-group-apply').addEventListener('click', function () {
        var ids = selected().map(function (r) { return r.getAttribute('data-item-id'); }).filter(Boolean);
        if (!ids.length) { return; }
        var form = document.getElementById('dp-group-form');
        form.querySelector('input[name="group_label"]').value = document.getElementById('dp-group-input').value;
        fillIds('dp-group-ids', 'item_ids[]', ids);
        form.submit();
    });

    // ── Drag rows (or a whole wave) onto rail chevrons. ──────────────
    itemRows.forEach(function (r) {
        if (!r.getAttribute('data-item-id')) { return; }
        r.setAttribute('draggable', 'true');
        r.addEventListener('dragstart', function (e) {
            var ids;
            var box = r.querySelector('.dp-check');
            if (box && box.checked) {
                ids = selected().map(function (s) { return s.getAttribute('data-item-id'); }).filter(Boolean);
            } else {
                ids = [r.getAttribute('data-item-id')];
            }
            e.dataTransfer.setData('text/plain', ids.join(','));
            e.dataTransfer.effectAllowed = 'move';
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.dp-wave-row[data-wave-id]'), function (row) {
        row.addEventListener('dragstart', function (e) {
            var waveId = row.getAttribute('data-wave-id');
            var ids = itemRows
                .filter(function (r) { return r.getAttribute('data-wave-id') === waveId; })
                .map(function (r) { return r.getAttribute('data-item-id'); })
                .filter(Boolean);
            e.dataTransfer.setData('text/plain', ids.join(','));
            e.dataTransfer.effectAllowed = 'move';
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('#dp-rail .dp-chev'), function (chev) {
        chev.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            chev.classList.add('dp-dropover');
        });
        chev.addEventListener('dragleave', function () { chev.classList.remove('dp-dropover'); });
        chev.addEventListener('drop', function (e) {
            e.preventDefault();
            chev.classList.remove('dp-dropover');
            var ids = (e.dataTransfer.getData('text/plain') || '').split(',').filter(Boolean);
            if (!ids.length) { return; }
            var form = document.getElementById('dp-stage-form');
            form.querySelector('input[name="stage_id"]').value = chev.getAttribute('data-stage-id');
            fillIds('dp-stage-ids', 'item_ids[]', ids);
            form.submit();
        });
    });
})();
</script>
</div></div>
@stop
