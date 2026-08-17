{{-- Day-precise Gantt of the FY's waves: arrival + deploy window bars per
     wave on a month-gridded axis, staff-OOO blackouts as faint striped
     bands, a today marker, collision warnings. Shared by the Deployments
     board and the Forecast planning hub.
     Expects $timeline (DeploymentTimeline::build output). --}}
@once
@push('css')
<style>
    .gantt { position: relative; --gantt-rail: 230px; }
    .gantt-row {
        display: grid;
        grid-template-columns: var(--gantt-rail) minmax(0, 1fr);
        align-items: stretch;
        border-top: 1px solid var(--table-border-row-color, #f0f0f0);
    }
    .gantt-row:first-child { border-top: 0; }
    .gantt-label {
        padding: 8px 12px 8px 0;
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .gantt-label .label { overflow: hidden; text-overflow: ellipsis; }
    .gantt-track { position: relative; min-height: 40px; }
    .gantt-head { border-top: 0; }
    .gantt-head .gantt-track { min-height: 24px; }
    .gantt-head .gantt-label {
        font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .5px;
        color: var(--text-help, #999);
    }
    {{-- Month gridlines span the whole chart from one overlay, so every
         row sits on the same verticals. --}}
    .gantt-gridlines { position: absolute; top: 0; bottom: 0; left: var(--gantt-rail); right: 0; pointer-events: none; }
    .gantt-gridline { position: absolute; top: 0; bottom: 0; border-left: 1px solid var(--table-border-row-color, #ececec); }
    .gantt-today { position: absolute; top: 0; bottom: 0; border-left: 2px solid var(--text-danger, #d9534f); opacity: .7; }
    .gantt-month-label {
        position: absolute; top: 3px; padding-left: 6px;
        font-size: 11px; color: var(--text-help, #999); white-space: nowrap;
    }
    .gantt-bar {
        position: absolute; height: 13px; border-radius: 3px;
        min-width: 4px; z-index: 1;
    }
    .gantt-bar-arrival { top: 6px; }
    .gantt-bar-deploy { top: 22px; opacity: .55; }
    {{-- Range text sits AFTER its bar, never inside it — inside, any bar
         narrower than its own caption turned into overlapping smear. --}}
    .gantt-bar-caption {
        position: absolute; padding-left: 6px; z-index: 1;
        font-size: 10px; line-height: 13px; color: var(--text-help, #999); white-space: nowrap;
    }
    .gantt-band {
        position: absolute; top: 6px; height: 13px; border-radius: 3px; z-index: 1;
        background: repeating-linear-gradient(45deg, #95a5a6, #95a5a6 3px, #bdc3c7 3px, #bdc3c7 6px);
    }
    .gantt-band-shadow {
        position: absolute; top: 0; bottom: 0; z-index: 0;
        background: repeating-linear-gradient(45deg, rgba(149,165,166,.10), rgba(149,165,166,.10) 4px, rgba(189,195,199,.10) 4px, rgba(189,195,199,.10) 8px);
    }
</style>
@endpush
@endonce
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
    <div class="box-body">
        @if (($timeline['waves_with_collision'] ?? 0) > 0)
            <div class="alert alert-warning" style="margin:0 0 12px;">
                <i class="fas fa-exclamation-triangle"></i>
                {{ trans('admin/deployments/general.timeline_collision_callout', ['count' => $timeline['waves_with_collision']]) }}
            </div>
        @endif
        @if (count($timeline['months']) === 0)
            <p class="text-center text-muted" style="margin:20px 0;">{{ trans('admin/deployments/general.timeline_empty') }}</p>
        @else
            @php($bands = $timeline['blackout_bands'] ?? [])
            <div class="gantt">
                {{-- One set of verticals for the whole chart. --}}
                <div class="gantt-gridlines">
                    @foreach ($timeline['months'] as $m)
                        @if ($m['offsetPct'] > 0)
                            <div class="gantt-gridline" style="left: {{ $m['offsetPct'] }}%;"></div>
                        @endif
                    @endforeach
                    @if (($timeline['today_pct'] ?? null) !== null)
                        <div class="gantt-today" style="left: {{ $timeline['today_pct'] }}%;" title="{{ trans('admin/deployments/general.timeline_today') }}"></div>
                    @endif
                </div>

                {{-- Month header, labels pinned to their column starts. --}}
                <div class="gantt-row gantt-head">
                    <div class="gantt-label">{{ trans('admin/deployments/general.wave') }}</div>
                    <div class="gantt-track">
                        @foreach ($timeline['months'] as $m)
                            <div class="gantt-month-label" style="left: {{ $m['offsetPct'] }}%;">{{ $m['label'] }}</div>
                        @endforeach
                    </div>
                </div>

                {{-- Staff OOO strip: each blackout as a striped band. --}}
                @if (count($bands) > 0)
                    <div class="gantt-row">
                        <div class="gantt-label">
                            <span class="text-muted" style="font-size:11px;"><i class="fas fa-user-clock"></i> {{ trans('admin/deployments/general.timeline_blackouts_label') }}</span>
                        </div>
                        <div class="gantt-track" style="min-height:26px;">
                            @foreach ($bands as $band)
                                <div class="gantt-band" title="{{ $band['name'] }}: {{ $band['label'] }}"
                                     style="left: {{ $band['offsetPct'] }}%; width: {{ $band['widthPct'] }}%;"></div>
                                @php($bandEnd = $band['offsetPct'] + $band['widthPct'])
                                <div class="gantt-bar-caption" style="top:6px; {{ $bandEnd > 82 ? 'right: '.(100 - $band['offsetPct']).'%; padding-right:6px; padding-left:0;' : 'left: '.$bandEnd.'%;' }}">{{ $band['name'] }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @foreach ($timeline['rows'] as $r)
                    <div class="gantt-row">
                        <div class="gantt-label">
                            @if (count($r['collisions'] ?? []) > 0)
                                <i class="fas fa-exclamation-triangle text-yellow" title="{{ trans('admin/deployments/general.timeline_collision_tooltip') }}: {{ collect($r['collisions'])->map(fn ($c) => $c['name'].' ('.$c['label'].')')->implode(', ') }}"></i>
                            @endif
                            <a class="js-lightbox" href="{{ route('deployment-waves.show', $r['wave']) }}" style="min-width:0;">
                                <span class="label" style="background-color: {{ $r['wave']->displayColor() }}; color:#fff; display:inline-block; max-width:100%;">{{ $r['wave']->name }}</span>
                            </a>
                        </div>
                        <div class="gantt-track">
                            @foreach ($bands as $band)
                                <div class="gantt-band-shadow" style="left: {{ $band['offsetPct'] }}%; width: {{ $band['widthPct'] }}%;"></div>
                            @endforeach
                            @if (! $r['has_dates'])
                                <span class="text-muted" style="font-size:11px; padding-left:6px; line-height:40px; position:relative; z-index:1;">{{ $r['past_label'] ?? trans('admin/deployments/general.timeline_no_dates') }}</span>
                            @else
                                @if ($r['arrival'])
                                    <div class="gantt-bar gantt-bar-arrival"
                                         title="{{ trans('admin/deployments/general.timeline_legend_arrival') }}: {{ $r['arrival']['label'] }}"
                                         style="left: {{ $r['arrival']['offsetPct'] }}%; width: {{ $r['arrival']['widthPct'] }}%; background-color: {{ $r['arrival']['color'] }};"></div>
                                    @php($aEnd = $r['arrival']['offsetPct'] + $r['arrival']['widthPct'])
                                    <div class="gantt-bar-caption" style="top:6px; {{ $aEnd > 82 ? 'right: '.(100 - $r['arrival']['offsetPct']).'%; padding-right:6px; padding-left:0;' : 'left: '.$aEnd.'%;' }}">{{ $r['arrival']['label'] }}</div>
                                @endif
                                @if ($r['deploy'])
                                    <div class="gantt-bar gantt-bar-deploy"
                                         title="{{ trans('admin/deployments/general.timeline_legend_deploy') }}: {{ $r['deploy']['label'] }}"
                                         style="left: {{ $r['deploy']['offsetPct'] }}%; width: {{ $r['deploy']['widthPct'] }}%; background-color: {{ $r['deploy']['color'] }};"></div>
                                    @php($dEnd = $r['deploy']['offsetPct'] + $r['deploy']['widthPct'])
                                    <div class="gantt-bar-caption" style="top:22px; {{ $dEnd > 82 ? 'right: '.(100 - $r['deploy']['offsetPct']).'%; padding-right:6px; padding-left:0;' : 'left: '.$dEnd.'%;' }}">{{ $r['deploy']['label'] }}</div>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
