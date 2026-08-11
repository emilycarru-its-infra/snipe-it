{{-- Month-grid Gantt of the FY's waves: arrival + deploy window bars per
     wave, staff-OOO blackouts as faint striped bands, collision warnings.
     Shared by the Deployments board and the Forecast planning hub.
     Expects $timeline (DeploymentTimeline::build output). --}}
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
            <div class="alert alert-warning" style="margin:0 0 12px;">
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
