{{-- Every procurement report, all rendered, one scrolling stream. The
     sticky pill strip mirrors the pipeline's five stage columns: pills are
     FILTERS, not selectors — nothing selected shows everything, a pipeline
     chevron narrows to its stage, and pills narrow further inside that. --}}
@php
    $reportsByStage = collect(['budgeting', 'ordering', 'deploying', 'reconciling', 'completed'])
        ->mapWithKeys(fn ($s) => [$s => $procReports->where('stage', $s)->values()]);
@endphp
<style>
    {{-- The strip is the reports' filter toolbar, not the pipeline's footer:
         a soft tinted band with surface-filled chips reads as controls for
         what follows, while the fifth-lines keep running through it. --}}
    .pr-pills-sticky {
        position: sticky;
        top: var(--header-h, 68px);
        z-index: 30;
        background: color-mix(in srgb, var(--pp-ink, #333a40) 4%, var(--pp-surface, #fff));
        border-top: 1px solid var(--pp-line, #e4e9ee);
        margin-top: 12px;
        padding: 0 0 6px;
        box-shadow: 0 1px 0 var(--pp-line, #e4e9ee);
    }
    {{-- Legend-style section name: sits ON the band's top border, so the
         fifth columns and their lines keep running underneath, and when the
         band sticks under the app bar the label tucks behind the header by
         itself. --}}
    .pr-strip-legend {
        position: absolute; top: -9px; left: 50%; transform: translateX(-50%);
        background: var(--pp-surface, #fff);
        padding: 0 12px; font-size: 11px; font-weight: 700;
        letter-spacing: .09em; text-transform: uppercase;
        color: var(--pp-ink3, #8a97a3); line-height: 18px; white-space: nowrap;
        transition: opacity .15s ease;
    }
    {{-- Stuck under the app bar the legend would peek out half-clipped, so
         it fades away while the strip is pinned (sentinel observer below). --}}
    .pr-pills-sticky.pr-stuck .pr-strip-legend { opacity: 0; }
    .pr-pill-scroll { overflow-x: auto; }
    .pr-pill-grid { display: grid; grid-template-columns: repeat(5, minmax(200px, 1fr)); gap: 0; min-width: 1080px; }
    {{-- Pills sit vertically centered in the strip so short columns keep
         the same breathing room above and below as the tallest one. --}}
    .pr-pill-col { min-width: 0; padding: 10px 10px; display: flex; flex-wrap: wrap; align-content: center; align-items: center; justify-content: center; }
    .pr-pill-col:first-child { padding-left: 0; }
    .pr-pill-col:last-child { padding-right: 0; }
    .pr-pill-col + .pr-pill-col { border-left: 1px solid var(--pp-line, #e4e9ee); }
    .pr-pill-col.pr-col-muted .pr-pill { display: none; }
    .pr-pill {
        display: inline-block; padding: 2px 10px; margin: 0 4px 4px 0;
        font-size: 12px; border-radius: 999px; cursor: pointer;
        border: 1px solid var(--pp-line, #e4e9ee); background: var(--pp-surface, #fff);
        color: var(--pp-ink2, #62707e); line-height: 1.6; text-decoration: none;
    }
    .pr-pill:hover, .pr-pill:focus { text-decoration: none; background: color-mix(in srgb, var(--pr-tab-c, #3c8dbc) 8%, transparent); color: var(--pp-ink, #333a40); }
    .pr-pill.active {
        background: color-mix(in srgb, var(--pr-tab-c, #3c8dbc) 16%, var(--pp-surface, #fff));
        border-color: var(--pr-tab-c, #3c8dbc);
        color: var(--pp-ink, #333a40);
    }
    .pr-report-pane {
        padding: 14px 0 16px;
        border-bottom: 1px solid var(--pp-line, #e4e9ee);
    }
    .pr-report-pane:last-child { border-bottom: 0; }
    .pr-report-pane.pr-hidden { display: none; }
</style>
<div id="pr-reports" style="margin-top:0; padding-top:0;">

        <div class="pr-strip-sentinel" aria-hidden="true"></div>
        <div class="pr-pills-sticky">
            <div class="pr-strip-legend">{{ trans('general.reports') }}</div>
            @if (! empty($hiddenReports))
            <div style="float:right; font-size:12px; padding-right:4px;">
                <a href="#" id="show-all-procurement-reports">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                    {{ trans('admin/purchase-orders/general.reports_hidden_count', ['count' => count($hiddenReports)]) }}
                </a>
            </div>
        @endif
        <div class="pr-pill-scroll">
            <div class="pr-pill-grid">
                    @foreach ($reportsByStage as $stage => $group)
                        <div class="pr-pill-col" data-report-stage="{{ $stage }}" style="--pr-tab-c: var(--pp-{{ $stage }})">
                            @foreach ($group as $report)
                                <a href="#proc-{{ $report['name'] }}" class="pr-pill" data-pr-report="proc-{{ $report['name'] }}">{{ trans('admin/purchase-orders/general.'.$report['name']) }}</a>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="proc-pipe">
        @foreach ($procReports as $report)
            <div class="proc-report-box pr-report-pane" id="proc-{{ $report['name'] }}" data-report-stage="{{ $report['stage'] }}" style="scroll-margin-top: 140px;">
                <div class="box-header with-border" style="padding-left:0; padding-right:0;">
                    <h3 class="box-title">
                        <a href="{{ $reportLink($report['route']) }}">{{ trans('admin/purchase-orders/general.'.$report['name']) }}</a>
                    </h3>
                    <div class="box-tools pull-right">
                        <a href="{{ $reportLink($report['route']) }}" class="btn btn-box-tool" data-tooltip="true" title="{{ trans('general.view') }}">
                            <x-icon type="reports" />
                        </a>
                        <a href="{{ $reportLink($report['route'], ['format' => 'csv']) }}" class="btn btn-box-tool" data-tooltip="true" title="{{ trans('admin/purchase-orders/general.disposition_download_csv') }}">
                            <x-icon type="download" />
                        </a>
                        @if ($report['route'] === 'reports.procurement.disposition-grid')
                            <a href="{{ $reportLink($report['route'], ['format' => 'xlsx']) }}" class="btn btn-box-tool" data-tooltip="true" title="{{ trans('admin/purchase-orders/general.disposition_download_xlsx') }}">
                                <i class="fa-solid fa-file-excel" aria-hidden="true"></i>
                            </a>
                        @endif
                        <a href="#" class="btn btn-box-tool hide-procurement-report"
                           data-report="{{ $report['name'] }}"
                           data-tooltip="true" title="{{ trans('admin/purchase-orders/general.report_hide') }}">
                            <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
                        </a>
                    </div>
                    <p class="text-muted proc-report-desc">{{ trans('admin/purchase-orders/general.'.$report['desc']) }}</p>
                </div>
                @if ($report['route'] === 'reports.procurement.lease-decisions')
                    @can('procurement.edit')
                        <div style="margin:10px 0;">
                            @include('partials.lease-document-drop')
                        </div>
                    @endcan
                @endif
                <div class="proc-report-body" data-embed-url="{{ $reportLink($report['route'], ['embed' => 1]) }}">
                    <div class="text-center text-muted" style="padding:18px;">
                        <i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                    </div>
                </div>
            </div>
        @endforeach
        </div>

</div>
<script nonce="{{ csrf_token() }}">
    // Fade the strip's legend while the strip is pinned under the app bar:
    // the sentinel sits directly above the strip, so the moment it scrolls
    // out under the header the strip is stuck.
    (function () {
        var strip = document.querySelector('.pr-pills-sticky');
        var sentinel = document.querySelector('.pr-strip-sentinel');
        if (! strip || ! sentinel || ! ('IntersectionObserver' in window)) { return; }
        var headerH = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header-h'), 10) || 68;
        new IntersectionObserver(function (entries) {
            strip.classList.toggle('pr-stuck', ! entries[0].isIntersecting);
        }, { rootMargin: '-' + (headerH + 1) + 'px 0px 0px 0px' }).observe(sentinel);
    })();
</script>
