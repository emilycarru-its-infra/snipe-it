@extends('layouts/default')

@section('title')
    {{ trans('admin/deployments/general.dashboard_title') }} @parent
@stop

@section('header_right')
    <a href="{{ route('deployment-config.index', 'types') }}" class="btn btn-sm btn-default"><i class="fas fa-cog"></i> {{ trans('admin/deployments/general.configure') }}</a>
    <a href="{{ route('deployments.forecast', ['fiscal_year' => $fy]) }}" class="btn btn-sm btn-default"><i class="fas fa-calendar-alt"></i> {{ trans('admin/deployments/general.forecast') }}</a>
    <a href="{{ route('deployments.storage') }}" class="btn btn-sm btn-default"><i class="fas fa-boxes"></i> {{ trans('admin/deployments/general.storage_title') }}</a>
    <a href="{{ route('deployments.blackouts.index') }}" class="btn btn-sm btn-default"><i class="fas fa-user-clock"></i> {{ trans('admin/deployments/general.blackouts_button') }}</a>
    <a href="{{ $downloadUrl }}" class="btn btn-sm btn-default"><i class="fas fa-download"></i> {{ trans('admin/deployments/general.download') }}</a>
    <a href="{{ route('deployment-waves.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> {{ trans('admin/deployments/general.add_wave') }}</a>
@stop

@section('content')
<div class="row"><div class="col-md-12">

{{-- Filters --}}
<div class="row">
    <div class="col-md-12">
        <form method="GET" action="{{ route('reports.deployments') }}" class="form-inline" style="margin-bottom:15px;">
            <div class="form-group">
                <label>{{ trans('admin/deployments/general.filter_fiscal_year') }}</label>
                <select name="fiscal_year" class="form-control" onchange="this.form.submit()">
                    @foreach ($fiscalYears as $y)
                        <option value="{{ $y }}" {{ (string) $fy === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>{{ trans('admin/deployments/general.filter_type') }}</label>
                <select name="deployment_type" class="form-control" onchange="this.form.submit()">
                    <option value="">{{ trans('admin/deployments/general.all_types') }}</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}" {{ (string) $typeFilter === (string) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>{{ trans('admin/deployments/general.filter_stage') }}</label>
                <select name="stage" class="form-control" onchange="this.form.submit()">
                    <option value="">{{ trans('admin/deployments/general.all_stages') }}</option>
                    @foreach ($stages as $st)
                        <option value="{{ $st->id }}" {{ (string) $stageFilter === (string) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
</div>

{{-- Device-flow chevron rail: the whole funnel at a glance, one chevron
     per catalog stage (Planned → … → Deployed). Clicking a chevron applies
     the same ?stage= filter as the select above; the terminal stage renders
     in its own colour even at zero so "nothing deployed yet" is visible.
     Geometry mirrors the procurement pipeline rail. --}}
<style>
    .dp-rail-scroll { overflow-x: auto; margin-bottom: 15px; }
    .dp-rail { display: flex; min-width: 900px; padding: 2px 0; }
    .dp-chev {
        flex: 1 1 0; position: relative; padding: 10px 16px 12px 30px;
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%, 16px 50%);
        margin-right: -11px;
        text-decoration: none;
        background: color-mix(in srgb, var(--dp-c) 10%, var(--box-bg, #fff));
    }
    .dp-chev:first-child {
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%);
        padding-left: 18px;
    }
    .dp-chev:hover, .dp-chev:focus { text-decoration: none; background: color-mix(in srgb, var(--dp-c) 20%, var(--box-bg, #fff)); }
    .dp-chev.selected { background: var(--dp-c); }
    .dp-chev .dp-stage { font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--dp-c); }
    .dp-chev .dp-big { font-size: 20px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; color: var(--color-fg, #333); }
    .dp-chev.selected .dp-stage, .dp-chev.selected .dp-big { color: #fff; }
</style>
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.rail_title') }}</h3>
        <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ trans('admin/deployments/general.rail_hint') }}</span>
    </div>
    <div class="box-body">
        <div class="dp-rail-scroll">
            <div class="dp-rail">
                @foreach ($stageRail as $rs)
                    @php($selected = (string) $stageFilter === (string) $rs['id'])
                    <a class="dp-chev {{ $selected ? 'selected' : '' }}"
                       style="--dp-c: {{ $rs['color'] }}"
                       href="{{ route('reports.deployments', array_filter(['fiscal_year' => $fy, 'deployment_type' => $typeFilter, 'stage' => $selected ? null : $rs['id']])) }}">
                        <div class="dp-stage">{{ $rs['name'] }}</div>
                        <div class="dp-big">{{ $rs['count'] }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- Lease-end / EOL look-ahead: the full candidate list for the selected
     FY, so picking a future year (say FY2027-28) shows every device
     expected to come due — the planning list exists before any wave does.
     The forecast picker is one click away to pull them onto a wave. --}}
@if ($forecastAssets->count() > 0)
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ trans('admin/deployments/general.forecast_summary', ['count' => $forecastAssets->count(), 'fy' => $fy]) }}
        </h3>
        <div class="box-tools pull-right">
            <a href="{{ route('deployments.forecast', ['fiscal_year' => $fy]) }}" class="btn btn-sm btn-primary">
                <i class="fas fa-plus"></i> {{ trans('admin/deployments/general.add_from_forecast') }}
            </a>
        </div>
    </div>
    <div class="box-body no-padding" style="max-height:420px; overflow:auto;">
        <table class="table table-hover table-striped table-condensed" style="margin-bottom:0;">
            <thead>
                <tr>
                    <th>{{ trans('admin/deployments/general.device') }}</th>
                    <th>{{ trans('admin/deployments/general.model') }}</th>
                    <th>{{ trans('admin/deployments/general.refresh_reason') }}</th>
                    <th>{{ trans('admin/deployments/general.source_date') }}</th>
                    <th>{{ trans('general.status') }}</th>
                    <th>{{ trans('admin/deployments/general.location') }}</th>
                </tr>
            </thead>
            <tbody>
            @php($reasonLabel = ['eol' => trans('admin/deployments/general.reason_eol'), 'lease' => trans('admin/deployments/general.reason_lease'), 'both' => trans('admin/deployments/general.reason_both')])
            @foreach ($forecastAssets as $asset)
                <tr>
                    <td><a href="{{ route('hardware.show', $asset) }}" class="js-lightbox">{{ $asset->name ?: $asset->asset_tag ?: ('#'.$asset->id) }}</a></td>
                    <td>{{ $asset->model?->name ?: '—' }}</td>
                    <td><span class="label label-default">{{ $reasonLabel[$asset->refresh_reason] ?? $asset->refresh_reason }}</span></td>
                    <td>{{ $asset->source_date ?: '—' }}</td>
                    <td>{{ $asset->status?->name ?: '—' }}</td>
                    <td>{{ $asset->location?->name ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- The unfunded counterweight to the look-ahead above: leases carry
     pre-approved replacement money from signing, but the Active (Legacy)
     fleet is aging in daily use with no replacement plan or funding in
     sight. Exec readers should see both stories side by side. --}}
@if (($legacyFleet['count'] ?? 0) > 0)
<div class="box box-default" style="border-left:4px solid #dd4b39;">
    <div class="box-header with-border">
        <h3 class="box-title">
            <i class="fas fa-hourglass-half text-red" aria-hidden="true"></i>
            {{ trans('admin/deployments/general.legacy_title', ['count' => $legacyFleet['count']]) }}
        </h3>
        <div class="box-tools pull-right">
            <a href="{{ url('hardware') }}?status_id={{ $legacyFleet['status_ids'][0] }}" class="btn btn-sm btn-default">
                {{ trans('admin/deployments/general.legacy_view_devices') }}
            </a>
        </div>
    </div>
    <div class="box-body">
        <p style="margin:0 0 8px;">
            {{ trans('admin/deployments/general.legacy_note', [
                'age' => $legacyFleet['avg_age_years'] ?? '—',
                'oldest' => $legacyFleet['oldest_year'] ?? '—',
            ]) }}
        </p>
        @foreach ($legacyFleet['by_model'] as $row)
            <span class="label" style="background-color:#dd4b39; color:#fff; display:inline-block; margin:0 4px 4px 0;">
                {{ $row['model'] }} · {{ $row['count'] }}
            </span>
        @endforeach
    </div>
</div>
@endif

{{-- Donut + count widgets --}}
<div class="row">
    @php($cards = [
        ['key' => 'stage', 'title' => trans('admin/deployments/general.widget_stage'), 'canvas' => 'deployStageChart'],
        ['key' => 'type', 'title' => trans('admin/deployments/general.widget_type'), 'canvas' => 'deployTypeChart'],
        ['key' => 'model', 'title' => trans('admin/deployments/general.widget_model'), 'canvas' => 'deployModelChart'],
    ])
    @foreach ($cards as $card)
        <div class="col-md-4">
            <div class="box box-default">
                <div class="box-header with-border"><h3 class="box-title">{{ $card['title'] }}</h3></div>
                <div class="box-body">
                    <div style="position:relative; height:200px; margin-bottom:10px;">
                        <canvas id="{{ $card['canvas'] }}"></canvas>
                    </div>
                    <table class="table table-striped" style="margin-bottom:0;">
                        <tbody>
                        @forelse ($widgets[$card['key']]['rows'] as $r)
                            <tr>
                                <td><span class="label" style="background-color: {{ $r['color'] }}; color:#fff;">{{ $r['label'] }}</span></td>
                                <td class="text-right"><strong>{{ $r['count'] }}</strong></td>
                                <td class="text-right text-muted">{{ $r['pct'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">—</td></tr>
                        @endforelse
                        </tbody>
                        <tfoot><tr><th>{{ trans('admin/deployments/general.total') }}</th><th class="text-right">{{ $widgets['total'] }}</th><th></th></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Timeline / Gantt (P2a) --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.timeline_title') }}</h3>
        <div class="box-tools pull-right">
            <span class="text-muted" style="font-size:12px;">
                <span style="display:inline-block; width:12px; height:12px; background:#2980b9; border-radius:2px; vertical-align:middle;"></span>
                {{ trans('admin/deployments/general.timeline_legend_arrival') }}
                &nbsp;&nbsp;
                <span style="display:inline-block; width:12px; height:12px; background:#2980b9; opacity:0.45; border-radius:2px; vertical-align:middle;"></span>
                {{ trans('admin/deployments/general.timeline_legend_deploy') }}
                &nbsp;&nbsp;
                <span style="display:inline-block; width:12px; height:12px; vertical-align:middle; border-radius:2px;
                    background:repeating-linear-gradient(45deg,#95a5a6,#95a5a6 3px,#bdc3c7 3px,#bdc3c7 6px);"></span>
                {{ trans('admin/deployments/general.timeline_blackouts_label') }}
            </span>
        </div>
    </div>
    <div class="box-body table-responsive">
        @if (($timeline['waves_with_collision'] ?? 0) > 0)
            <div class="callout callout-warning" style="margin:0 0 12px;">
                <i class="fas fa-exclamation-triangle"></i>
                {{ trans('admin/deployments/general.timeline_collision_callout', ['count' => $timeline['waves_with_collision']]) }}
            </div>
        @endif
        @if (count($timeline['months']) === 0)
            <p class="text-center text-muted" style="margin:20px 0;">{{ trans('admin/deployments/general.timeline_empty') }}</p>
        @else
            @php($colCount = count($timeline['months']))
            <table class="table table-condensed" style="margin-bottom:0; table-layout:fixed;">
                <thead>
                    <tr>
                        <th style="width:200px;">{{ trans('admin/deployments/general.wave') }}</th>
                        @foreach ($timeline['months'] as $m)
                            <th class="text-center text-muted" style="font-weight:normal; font-size:11px;">{{ $m['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @php($bands = $timeline['blackout_bands'] ?? [])
                @if (count($bands) > 0)
                    {{-- Staff OOO header strip: each blackout as a faint striped band on the month axis. --}}
                    <tr>
                        <td><span class="text-muted" style="font-size:11px;"><i class="fas fa-user-clock"></i> {{ trans('admin/deployments/general.timeline_blackouts_label') }}</span></td>
                        <td colspan="{{ $colCount }}" style="position:relative; padding:0;">
                            <div style="position:relative; height:20px;">
                                @foreach ($bands as $band)
                                    <div title="{{ $band['name'] }}: {{ $band['label'] }}"
                                         style="position:absolute; top:3px; height:14px; border-radius:3px;
                                                left: {{ $band['offsetPct'] }}%; width: {{ $band['widthPct'] }}%;
                                                background:repeating-linear-gradient(45deg,#95a5a6,#95a5a6 3px,#bdc3c7 3px,#bdc3c7 6px);
                                                overflow:hidden; white-space:nowrap;">
                                        <span style="color:#fff; font-size:10px; padding-left:4px; line-height:14px;">{{ $band['name'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @endif
                @foreach ($timeline['rows'] as $r)
                    <tr>
                        <td>
                            @if (count($r['collisions'] ?? []) > 0)
                                <i class="fas fa-exclamation-triangle text-yellow" title="{{ trans('admin/deployments/general.timeline_collision_tooltip') }}: {{ collect($r['collisions'])->map(fn ($c) => $c['name'].' ('.$c['label'].')')->implode(', ') }}"></i>
                            @endif
                            <a href="{{ route('deployment-waves.show', $r['wave']) }}">
                                <span class="label" style="background-color: {{ $r['wave']->displayColor() }}; color:#fff;">{{ $r['wave']->name }}</span>
                            </a>
                        </td>
                        <td colspan="{{ $colCount }}" style="position:relative; padding:0;">
                            {{-- Faint blackout bands behind the wave bars (visually subordinate). --}}
                            @foreach ($bands as $band)
                                <div style="position:absolute; top:0; bottom:0; z-index:0;
                                            left: {{ $band['offsetPct'] }}%; width: {{ $band['widthPct'] }}%;
                                            background:repeating-linear-gradient(45deg,rgba(149,165,166,0.10),rgba(149,165,166,0.10) 4px,rgba(189,195,199,0.10) 4px,rgba(189,195,199,0.10) 8px);"></div>
                            @endforeach
                            @if (! $r['has_dates'])
                                <span class="text-muted" style="font-size:11px; padding-left:6px; position:relative; z-index:1;">{{ trans('admin/deployments/general.timeline_no_dates') }}</span>
                            @else
                                <div style="position:relative; height:38px; z-index:1;">
                                    @if ($r['arrival'])
                                        <div title="{{ trans('admin/deployments/general.timeline_legend_arrival') }}: {{ $r['arrival']['label'] }}"
                                             style="position:absolute; top:3px; height:14px; border-radius:3px;
                                                    left: {{ $r['arrival']['offsetPct'] }}%; width: {{ $r['arrival']['widthPct'] }}%;
                                                    background-color: {{ $r['arrival']['color'] }}; overflow:hidden; white-space:nowrap;">
                                            <span style="color:#fff; font-size:10px; padding-left:4px; line-height:14px;">{{ $r['arrival']['label'] }}</span>
                                        </div>
                                    @endif
                                    @if ($r['deploy'])
                                        <div title="{{ trans('admin/deployments/general.timeline_legend_deploy') }}: {{ $r['deploy']['label'] }}"
                                             style="position:absolute; top:20px; height:14px; border-radius:3px; opacity:0.55;
                                                    left: {{ $r['deploy']['offsetPct'] }}%; width: {{ $r['deploy']['widthPct'] }}%;
                                                    background-color: {{ $r['deploy']['color'] }}; overflow:hidden; white-space:nowrap;">
                                            <span style="color:#fff; font-size:10px; padding-left:4px; line-height:14px;">{{ $r['deploy']['label'] }}</span>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

{{-- Waves table --}}
<div class="box box-default">
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

{{-- Decommissioning — the reverse flow. Derived entirely from fields the
     devices already carry: Processing* status = collecting, decommission
     date = out the door, archived status = terminal. Deep per-device work
     happens on the Disposition Grid; this lane is the operational rollup —
     what is being gathered, and which rooms the pickup has to visit. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.decom_title') }}</h3>
        <span class="text-muted" style="font-size:12px; margin-left:10px;">{{ trans('admin/deployments/general.decom_hint') }}</span>
        <div class="box-tools pull-right">
            <a href="{{ route('reports.procurement.disposition-grid') }}" class="btn btn-sm btn-default">
                {{ trans('admin/deployments/general.decom_open_disposition') }}
            </a>
        </div>
    </div>
    <div class="box-body">
        <div class="dp-rail-scroll">
            <div class="dp-rail" style="min-width:640px;">
                @php($decomStages = [
                    ['label' => trans('admin/deployments/general.decom_collecting'), 'note' => trans('admin/deployments/general.decom_collecting_note'), 'count' => $decommission['collectingCount'], 'color' => '#1f9e8e'],
                    ['label' => trans('admin/deployments/general.decom_decommissioned'), 'note' => trans('admin/deployments/general.decom_decommissioned_note'), 'count' => $decommission['decommissionedCount'], 'color' => '#c8860a'],
                    ['label' => trans('admin/deployments/general.decom_archived'), 'note' => trans('admin/deployments/general.decom_archived_note'), 'count' => $decommission['archivedCount'], 'color' => '#95a5a6'],
                ])
                @foreach ($decomStages as $ds)
                    <div class="dp-chev" style="--dp-c: {{ $ds['color'] }}; cursor:default;">
                        <div class="dp-stage">{{ $ds['label'] }}</div>
                        <div class="dp-big">{{ $ds['count'] }}</div>
                        <div class="text-muted" style="font-size:11.5px; line-height:1.35; margin-top:2px;">{{ $ds['note'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        @if ($decommission['collectingCount'] === 0)
            <p class="text-muted" style="margin:10px 0 0;">{{ trans('admin/deployments/general.decom_none') }}</p>
        @else
            <div class="row" style="margin-top:15px;">
                <div class="col-md-8">
                    <div class="table-responsive">
                        <table class="table table-striped table-condensed" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th>{{ trans('admin/deployments/general.decom_col_asset') }}</th>
                                    <th>{{ trans('admin/deployments/general.decom_col_model') }}</th>
                                    <th>{{ trans('admin/deployments/general.decom_col_status') }}</th>
                                    <th>{{ trans('admin/deployments/general.decom_col_location') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.lease_provider') }}</th>
                                    <th>{{ trans('admin/deployments/general.decom_col_lease_end') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($decommission['collectingRows'] as $row)
                                    <tr>
                                        <td><a href="{{ route('hardware.show', $row['id']) }}" class="js-lightbox">{{ $row['asset_tag'] }}</a></td>
                                        <td>{{ $row['model'] ?: '—' }}</td>
                                        <td>{{ $row['status'] ?: '—' }}</td>
                                        <td>{{ $row['location'] ?: '—' }}</td>
                                        <td>{{ $row['lessor'] ?: '—' }}</td>
                                        <td>{{ $row['lease_end_date'] ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($decommission['collectingMore'] > 0)
                        <p class="text-muted" style="font-size:12px; margin:8px 0 0;">
                            {{ trans('admin/deployments/general.decom_more', ['count' => $decommission['collectingMore']]) }}
                        </p>
                    @endif
                </div>
                <div class="col-md-4">
                    <h5 style="margin-top:0; font-weight:700;">{{ trans('admin/deployments/general.decom_locations') }}</h5>
                    <table class="table table-condensed" style="margin-bottom:10px;">
                        <tbody>
                            @foreach ($decommission['byLocation'] as $loc)
                                <tr>
                                    <td>{{ $loc['location'] }}</td>
                                    <td class="text-right"><strong>{{ $loc['count'] }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @foreach ($decommission['statuses'] as $status)
                        @if ($status['count'] > 0)
                            <span class="label" style="background-color:#1f9e8e; color:#fff; display:inline-block; margin:0 4px 4px 0;">
                                {{ $status['name'] }} · {{ $status['count'] }}
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>

<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
(function () {
    var donut = function (id, payload) {
        var el = document.getElementById(id);
        if (!el || !payload.labels.length) { return; }
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: payload.labels,
                datasets: [{ data: payload.data, backgroundColor: payload.colors }]
            },
            options: { responsive: true, maintainAspectRatio: false, legend: { position: 'right' } }
        });
    };
    donut('deployStageChart', @json($widgets['stage']['chart']));
    donut('deployTypeChart', @json($widgets['type']['chart']));
    donut('deployModelChart', @json($widgets['model']['chart']));
})();
</script>
</div></div>
@stop
