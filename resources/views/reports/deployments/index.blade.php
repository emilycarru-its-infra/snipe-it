@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.dashboard_title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('deployment-config.index', 'types') }}" class="btn btn-sm btn-default"><i class="fas fa-cog"></i> {{ trans('admin/deployments/general.configure') }}</a>
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
                <option value="{{ $t->id }}" {{ (string) $typeFilter === (string) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('deployments.forecast', ['fiscal_year' => $fy]) }}" class="btn btn-default"><i class="fas fa-calendar-alt"></i> {{ trans('admin/deployments/general.forecast') }}</a>
    <a href="{{ route('deployments.storage') }}" class="btn btn-default"><i class="fas fa-boxes"></i> {{ trans('admin/deployments/general.storage_title') }}</a>
    <a href="{{ route('deployments.blackouts.index') }}" class="btn btn-default"><i class="fas fa-user-clock"></i> {{ trans('admin/deployments/general.blackouts_button') }}</a>
    @can('deployments.edit')
        @include('reports.deployments._new-wave-popover', ['popoverId' => 'dp-new-wave', 'fy' => $fy, 'types' => $types])
    @endcan
</div>

{{-- Device-flow chevron rail. Counts are counts of ROWS in the device
     table below — one row per device whatever its source (wave item,
     refresh backlog, order line, past-year reconstruction) — so chevrons
     and table can never disagree. A chevron click filters the table;
     clicking the selected chevron again clears the filter. --}}
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
    .dp-scroll { max-height: 65vh; overflow: auto; }
    .dp-scroll thead th {
        position: sticky; top: 0; z-index: 2;
        background: var(--box-bg, #fff);
        box-shadow: 0 1px 0 var(--box-border-color, #f4f4f4);
    }
    .dp-group-head td {
        font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .05em;
        background: color-mix(in srgb, var(--main-theme-color, #3c8dbc) 8%, var(--box-bg, #fff));
        cursor: grab;
    }
    #dp-rows tr[draggable="true"] { cursor: grab; }
    /* Collapse chevron sits inside the group header, left of its checkbox. */
    .dp-group-toggle {
        background: none; border: 0; padding: 0 8px 0 0; color: inherit;
        cursor: pointer; font-size: 11px; opacity: .75;
    }
    .dp-group-toggle:hover { opacity: 1; }
    .dp-group-inner { display: flex; align-items: center; gap: 8px; }
    .dp-group-inner .dp-group-check { margin: 0; flex: 0 0 auto; }
    .dp-group-label { flex: 1 1 auto; }
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

{{-- Waves and the timeline side by side — the list and its calendar are
     one thought, and stacked they cost a screen each. --}}
@if ($waves->isNotEmpty())
<div class="row" style="display:flex; flex-wrap:wrap;">
    <div class="col-md-6" style="display:flex;">
<div class="box box-default" style="flex:1;">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.waves_title') }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover table-striped">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.name') }}</th>
                    <th>{{ trans('admin/deployments/general.deployment_type') }}</th>
                    <th>{{ trans('admin/deployments/general.wave_state') }}</th>
                    <th>{{ trans('admin/deployments/general.fiscal_year') }}</th>
                    <th class="text-right">{{ trans('admin/deployments/general.device') }}s</th>
                    <th>{{ trans('admin/deployments/general.arrival_window') }}</th>
                    <th>{{ trans('admin/deployments/general.deploy_window') }}</th>
                    <th>{{ trans('admin/deployments/general.owner') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($waves as $wave)
                <tr>
                    <td><a href="{{ route('deployment-waves.show', $wave) }}"><span class="label" style="background-color: {{ $wave->displayColor() }}; color:#fff;">{{ $wave->name }}</span></a></td>
                    <td>{{ $wave->typeLabel() }}</td>
                    <td>{{ ucfirst($wave->wave_state) }}</td>
                    <td>{{ $wave->fiscal_year ?: '—' }}</td>
                    <td class="text-right">{{ $wave->items_count }}</td>
                    <td>
                        @if ($wave->arrival_window_start || $wave->arrival_window_end)
                            {{ optional($wave->arrival_window_start)->toDateString() ?: '?' }} – {{ optional($wave->arrival_window_end)->toDateString() ?: '?' }}
                        @else — @endif
                    </td>
                    <td>
                        @if ($wave->target_start_date || $wave->target_end_date)
                            {{ optional($wave->target_start_date)->toDateString() ?: '?' }} – {{ optional($wave->target_end_date)->toDateString() ?: '?' }}
                        @else — @endif
                    </td>
                    <td>{{ $wave->owner?->full_name ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted">{{ trans('admin/deployments/general.no_waves') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
    </div>
    <div class="col-md-6" style="display:flex;">
        <div style="flex:1; min-width:0;">
            @include('reports.deployments._timeline')
        </div>
    </div>
</div>
@endif

{{-- The unified device table: one row per physical device in the FY's
     flow, whatever lens produced it. Chevrons filter it, Group regroups
     it, checkboxes and drag drive bulk moves. --}}
<div class="box box-default" id="devices-flow">
    <div class="box-header with-border">
        <h3 class="box-title" id="dp-title">{{ trans('admin/deployments/general.flow_devices_title', ['count' => count($deviceRows), 'fy' => $fy]) }}</h3>
        <a href="{{ route('deployments.forecast', ['fiscal_year' => $fy]) }}" class="btn btn-sm btn-primary" style="margin-left:12px; vertical-align:middle;">
            <i class="fas fa-plus"></i> {{ trans('admin/deployments/general.add_from_forecast') }}</a>
        <span style="margin-left:16px; vertical-align:middle; white-space:nowrap;">
            <span class="text-muted" style="font-size:12px; margin-right:4px;">{{ trans('admin/deployments/general.flow_group_label') }}</span>
            <span class="btn-group" id="dp-group-btns" style="vertical-align:middle;">
                <button type="button" class="btn btn-xs btn-default active" data-group="">{{ trans('admin/deployments/general.flow_group_none') }}</button>
                <button type="button" class="btn btn-xs btn-default" data-group="wave">{{ trans('admin/deployments/general.flow_group_wave') }}</button>
                <button type="button" class="btn btn-xs btn-default" data-group="group">{{ trans('admin/deployments/general.flow_group_group') }}</button>
                <button type="button" class="btn btn-xs btn-default" data-group="location">{{ trans('admin/deployments/general.flow_group_location') }}</button>
                <button type="button" class="btn btn-xs btn-default" data-group="type">{{ trans('admin/deployments/general.flow_group_type') }}</button>
                <button type="button" class="btn btn-xs btn-default" data-group="model">{{ trans('admin/deployments/general.flow_group_model') }}</button>
            </span>
        </span>
    </div>
    @if ($backlogCount > 0)
        <div class="box-body" style="padding-top:8px; padding-bottom:8px; border-bottom:1px solid var(--box-border-color, #f4f4f4);">
            <span class="text-muted" style="font-size:12.5px;">
                {{ $isPast
                    ? trans('admin/deployments/general.flow_backlog_note_past', ['count' => $backlogCount, 'fy' => $fy])
                    : trans('admin/deployments/general.flow_backlog_note', ['count' => $backlogCount]) }}
            </span>
        </div>
    @endif

    {{-- Bulk action bar: appears once anything is checked. Stage moves and
         grouping act on tracked (wave) rows; add-to-wave acts on backlog
         rows. Derived rows (orders, history) carry no checkbox. --}}
    <div class="dp-bulkbar" id="dp-bulkbar">
        <strong id="dp-sel-count"></strong>
        <span id="dp-move-wrap" style="margin-left:12px; display:none;">
            {{ trans('admin/deployments/general.flow_move_to') }}:
            @foreach ($stages as $stage)
                <button type="button" class="btn btn-xs btn-default dp-move-btn" data-stage-id="{{ $stage->id }}"
                        style="border-color: {{ $stage->color ?: '#bdc3c7' }};">
                    {{ $stage->name }}
                </button>
            @endforeach
            <span style="margin-left:10px;">
                <input type="text" id="dp-group-input" class="form-control input-sm" style="width:160px;" placeholder="{{ trans('admin/deployments/general.flow_set_group') }}">
                <button type="button" class="btn btn-xs btn-default" id="dp-group-apply">{{ trans('admin/deployments/general.flow_set_group') }}</button>
            </span>
            <span class="text-muted" style="font-size:12px; margin-left:8px;">{{ trans('admin/deployments/general.flow_gate_hint') }}</span>
        </span>
        <span id="dp-wave-wrap" style="margin-left:12px; display:none;">
            {{ trans('admin/deployments/general.flow_add_to_wave') }}:
            <select id="dp-wave-select" class="form-control input-sm">
                <option value="">{{ trans('admin/deployments/general.catalog_none') }}</option>
                @foreach ($waves as $wave)
                    <option value="{{ $wave->id }}">{{ $wave->name }}</option>
                @endforeach
            </select>
            <input type="text" id="dp-wave-new" class="form-control input-sm" placeholder="{{ trans('admin/deployments/general.new_wave_name') }}">
            <button type="button" class="btn btn-xs btn-primary" id="dp-wave-go">{{ trans('admin/deployments/general.add_from_forecast') }}</button>
        </span>
    </div>

    <div class="box-body no-padding dp-scroll">
        <table class="table table-hover table-striped table-condensed" style="margin-bottom:0;">
            <thead>
                <tr>
                    <th style="width:28px;"><input type="checkbox" id="dp-select-all"></th>
                    <th>{{ trans('admin/deployments/general.device') }}</th>
                    <th>{{ trans('admin/deployments/general.model') }}</th>
                    <th>{{ trans('admin/deployments/general.stage') }}</th>
                    <th>{{ trans('admin/deployments/general.wave') }}</th>
                    <th>{{ trans('admin/deployments/general.deployment_type') }}</th>
                    <th>{{ trans('admin/deployments/general.refresh_reason') }}</th>
                    <th>{{ trans('admin/deployments/general.source_date') }}</th>
                    <th>{{ trans('general.status') }}</th>
                    <th>{{ trans('admin/deployments/general.location') }}</th>
                </tr>
            </thead>
            <tbody id="dp-rows">
            @forelse ($deviceRows as $idx => $row)
                <tr data-idx="{{ $idx }}"
                    data-kind="{{ $row['kind'] }}"
                    data-stage="{{ $row['stage_slug'] }}"
                    data-type="{{ $row['type'] }}"
                    data-wave="{{ $row['wave'] ?: trans('admin/deployments/general.flow_backlog_stage') }}"
                    data-model="{{ $row['model'] }}"
                    data-group="{{ $row['group'] ?: '—' }}"
                    data-location="{{ $row['location'] }}"
                    @if ($row['item_id']) data-item-id="{{ $row['item_id'] }}" @endif
                    @if ($row['asset_id']) data-asset-id="{{ $row['asset_id'] }}" @endif>
                    <td>
                        @if ($row['item_id'] || $row['asset_id'])
                            <input type="checkbox" class="dp-check">
                        @endif
                    </td>
                    <td>
                        @if ($row['device_url'])
                            <a href="{{ $row['device_url'] }}" class="js-lightbox">{{ $row['device'] }}</a>
                        @else
                            {{ $row['device'] }}
                        @endif
                    </td>
                    <td>{{ $row['model'] }}</td>
                    <td><span class="label" style="background-color: {{ $row['stage_color'] }}; color:#fff;">{{ $row['stage_name'] }}</span></td>
                    <td>
                        @if ($row['wave'])
                            <a href="{{ $row['wave_url'] }}"><span class="label" style="background-color: {{ $row['wave_color'] }}; color:#fff;">{{ $row['wave'] }}</span></a>
                        @else — @endif
                    </td>
                    <td>{{ $row['type'] }}</td>
                    <td>{{ $row['context'] }}</td>
                    <td>{{ $row['due'] }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td>{{ $row['location'] }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted">{{ trans('admin/deployments/general.flow_empty') }}</td></tr>
            @endforelse
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
<form id="dp-wave-form" method="POST" action="{{ route('deployments.forecast.add') }}" style="display:none;">
    @csrf
    <input type="hidden" name="fiscal_year" value="{{ $fy }}">
    <input type="hidden" name="wave_id" value="">
    <input type="hidden" name="new_wave_name" value="">
    <span id="dp-wave-ids"></span>
</form>

{{-- Agreements live at /procurement/agreements — one hub, not a copy of
     the ledger on every page that touches the program. --}}

@include('reports.deployments._decommissioning')

<script nonce="{{ csrf_token() }}">
(function () {
    var tbody = document.getElementById('dp-rows');
    if (!tbody) { return; }

    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-idx]'));
    var stageFilter = '';
    var stageLabel = '';
    var groupBy = '';
    // Collapse state survives regrouping and stage filtering, keyed by
    // dimension + group so switching Wave → Model does not carry it over.
    var collapsedGroups = {};
    var NO_WAVE_KEY = @json(trans('admin/deployments/general.flow_backlog_stage'));
    var titleEl = document.getElementById('dp-title');
    var titleTemplate = @json(trans('admin/deployments/general.flow_devices_title', ['count' => '__N__', 'fy' => $fy]));
    var stageSuffix = @json(trans('admin/deployments/general.flow_stage_suffix', ['stage' => '__S__']));
    var emptyText = @json(trans('admin/deployments/general.flow_stage_empty'));

    function rebuild() {
        Array.prototype.forEach.call(tbody.querySelectorAll('tr.dp-group-head, tr.dp-stage-empty'), function (h) { h.remove(); });

        var visible = [];
        rows.forEach(function (r) {
            var show = !stageFilter || r.getAttribute('data-stage') === stageFilter;
            r.style.display = show ? '' : 'none';
            r.classList.remove('dp-collapsed');
            if (show) { visible.push(r); }
        });

        // The title always tells the truth about what is on screen.
        var title = titleTemplate.replace('__N__', visible.length);
        if (stageFilter && stageLabel) { title += ' ' + stageSuffix.replace('__S__', stageLabel); }
        titleEl.textContent = title;

        if (visible.length === 0 && rows.length > 0) {
            var emptyRow = document.createElement('tr');
            emptyRow.className = 'dp-stage-empty';
            var td = document.createElement('td');
            td.colSpan = 10;
            td.className = 'text-center text-muted';
            td.textContent = emptyText;
            emptyRow.appendChild(td);
            tbody.appendChild(emptyRow);
        }

        if (!groupBy) {
            // Ungrouped still sinks the unplanned tail: devices on no wave
            // read as noise above the waves that are actually scheduled.
            rows.slice().sort(function (a, b) {
                var aNo = a.getAttribute('data-wave') === NO_WAVE_KEY ? 1 : 0;
                var bNo = b.getAttribute('data-wave') === NO_WAVE_KEY ? 1 : 0;
                if (aNo !== bNo) { return aNo - bNo; }
                return (+a.getAttribute('data-idx')) - (+b.getAttribute('data-idx'));
            }).forEach(function (r) { tbody.appendChild(r); });
            return;
        }

        var groups = {};
        var order = [];
        visible.forEach(function (r) {
            var key = r.getAttribute('data-' + groupBy) || '—';
            if (!groups[key]) { groups[key] = []; order.push(key); }
            groups[key].push(r);
        });
        // Biggest cohort first, except the devices on no wave at all: those
        // are the unplanned tail, and reading them between real waves makes
        // the plan look emptier than it is. They always sit last.
        order.sort(function (a, b) {
            if (a === NO_WAVE_KEY) { return 1; }
            if (b === NO_WAVE_KEY) { return -1; }
            return groups[b].length - groups[a].length;
        });
        order.forEach(function (key) {
            var head = document.createElement('tr');
            head.className = 'dp-group-head';
            head.setAttribute('draggable', 'true');
            head.setAttribute('data-group-key', key);
            var td = document.createElement('td');
            td.colSpan = 10;
            // One flex row: the app styles checkboxes as display:grid, which
            // would otherwise break chevron, box and label onto three lines.
            var inner = document.createElement('div');
            inner.className = 'dp-group-inner';
            td.appendChild(inner);
            var check = document.createElement('input');
            check.type = 'checkbox';
            check.className = 'dp-group-check';
            inner.appendChild(check);
            // Collapse control: a wave is a unit of work, and a 160-device
            // wave otherwise buries every other wave on the page.
            var collapsed = collapsedGroups[groupBy + '|' + key] === true;
            var chev = document.createElement('button');
            chev.type = 'button';
            chev.className = 'dp-group-toggle';
            chev.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            chev.innerHTML = '<i class="fa-solid ' + (collapsed ? 'fa-chevron-right' : 'fa-chevron-down') + '" aria-hidden="true"></i>';
            inner.insertBefore(chev, check);
            var label = document.createElement('span');
            label.className = 'dp-group-label';
            label.textContent = key + ' · ' + groups[key].length;
            inner.appendChild(label);
            head.appendChild(td);
            tbody.appendChild(head);
            groups[key].forEach(function (r) {
                tbody.appendChild(r);
                if (collapsed) { r.style.display = 'none'; r.classList.add('dp-collapsed'); }
            });

            chev.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var nowCollapsed = ! (collapsedGroups[groupBy + '|' + key] === true);
                collapsedGroups[groupBy + '|' + key] = nowCollapsed;
                chev.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
                chev.innerHTML = '<i class="fa-solid ' + (nowCollapsed ? 'fa-chevron-right' : 'fa-chevron-down') + '" aria-hidden="true"></i>';
                groups[key].forEach(function (r) {
                    r.style.display = nowCollapsed ? 'none' : '';
                    r.classList.toggle('dp-collapsed', nowCollapsed);
                });
                refreshBulkbar();
            });

            // A group is a unit: its checkbox selects every row in it, and
            // dragging its header carries the whole cohort onto a chevron.
            check.addEventListener('change', function () {
                groups[key].forEach(function (r) {
                    var box = r.querySelector('.dp-check');
                    if (box) { box.checked = check.checked; }
                });
                refreshBulkbar();
            });
            head.addEventListener('dragstart', function (e) {
                var ids = groups[key].map(function (r) { return r.getAttribute('data-item-id'); }).filter(Boolean);
                e.dataTransfer.setData('text/plain', ids.join(','));
                e.dataTransfer.effectAllowed = 'move';
            });
        });
        rows.forEach(function (r) {
            if (visible.indexOf(r) === -1) { tbody.appendChild(r); }
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('#dp-rail .dp-chev'), function (chev) {
        chev.addEventListener('click', function (e) {
            e.preventDefault();
            var slug = chev.getAttribute('data-stage');
            var wasSelected = chev.classList.contains('selected');
            Array.prototype.forEach.call(document.querySelectorAll('#dp-rail .dp-chev'), function (c) { c.classList.remove('selected'); });
            stageFilter = wasSelected ? '' : slug;
            stageLabel = wasSelected ? '' : chev.querySelector('.dp-stage').textContent;
            if (!wasSelected) { chev.classList.add('selected'); }
            rebuild();
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('#dp-group-btns button'), function (btn) {
        btn.addEventListener('click', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#dp-group-btns button'), function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            groupBy = btn.getAttribute('data-group');
            rebuild();
        });
    });

    // ── Selection + bulk actions ─────────────────────────────────────
    var bulkbar = document.getElementById('dp-bulkbar');
    var selCount = document.getElementById('dp-sel-count');
    var moveWrap = document.getElementById('dp-move-wrap');
    var waveWrap = document.getElementById('dp-wave-wrap');

    function selected() {
        return rows.filter(function (r) {
            var box = r.querySelector('.dp-check');
            var hiddenByFilter = r.style.display === 'none' && ! r.classList.contains('dp-collapsed');
            return ! hiddenByFilter && box && box.checked;
        });
    }

    function refreshBulkbar() {
        var sel = selected();
        var items = sel.filter(function (r) { return r.getAttribute('data-item-id'); });
        var assets = sel.filter(function (r) { return r.getAttribute('data-asset-id'); });
        bulkbar.classList.toggle('active', sel.length > 0);
        selCount.textContent = @json(trans('admin/deployments/general.flow_selected', ['count' => '__N__'])).replace('__N__', sel.length);
        moveWrap.style.display = items.length > 0 ? '' : 'none';
        waveWrap.style.display = assets.length > 0 ? '' : 'none';
    }

    tbody.addEventListener('change', function (e) {
        if (e.target.classList.contains('dp-check')) { refreshBulkbar(); }
    });

    document.getElementById('dp-select-all').addEventListener('change', function () {
        var check = this.checked;
        rows.forEach(function (r) {
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

    // ── Drag rows (or whole groups) onto rail chevrons ───────────────
    // Dragging a checked row carries the whole selection; an unchecked
    // row moves alone. Backlog / derived rows carry no item id, and the
    // server gate keeps Planned→beyond honest regardless.
    rows.forEach(function (r) {
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
