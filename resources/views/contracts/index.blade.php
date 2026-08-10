@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/contracts/general.contracts') }}
    @parent
@stop

@section('header_right')
    @can('create', App\Models\Contract::class)
        <a href="{{ route('contracts.create') }}" class="btn btn-primary pull-right">
            {{ trans('general.create') }}
        </a>
    @endcan
@stop

{{-- Page content --}}
@section('content')
    @php
        $isActive      = request('is_active');
        $topLevelOnly  = filter_var(request('top_level'), FILTER_VALIDATE_BOOLEAN);
        $seriesOnly    = filter_var(request('synthesized_only'), FILTER_VALIDATE_BOOLEAN);
        $expiringDays  = request('expiring_within_days');
        $selectedTheme = request('theme');

        // The fiscal-year selector scopes the whole page — tiles, charts,
        // reports and the table below — so every number on screen is read
        // against the same window.
        $apiParams = array_filter([
            'fiscal_year'          => $selectedFy,
            'is_active'            => $isActive,
            'top_level'            => $topLevelOnly ? 'true' : null,
            'synthesized_only'     => $seriesOnly ? 'true' : null,
            'expiring_within_days' => $expiringDays,
            'theme'                => $selectedTheme,
        ], fn ($v) => $v !== null && $v !== '');

        // Which tile is lit: only one filter is ever "the" active one, and
        // "All Contracts" lights up when nothing but the FY scope is set.
        $tileFilters = array_diff_key($apiParams, ['fiscal_year' => null]);
        $noFilter = empty($tileFilters);

        // Links preserve the FY scope so clicking a tile narrows rather than
        // resets the page.
        $link = fn (array $params = []) => route('contracts.index', array_filter(
            array_merge(['fiscal_year' => $selectedFy], $params),
            fn ($v) => $v !== null && $v !== ''
        ));

        $tiles = [
            [
                'count' => number_format($totalCount),
                'label' => trans('admin/contracts/general.tile_all'),
                'href'  => $link(),
                'icon'  => 'fa-file-contract',
                'color' => $noFilter ? 'bg-blue' : 'bg-aqua',
            ],
            [
                'count' => number_format($activeCount),
                'label' => trans('admin/contracts/general.tile_active'),
                'href'  => $link(['is_active' => 'true']),
                'icon'  => 'fa-check-circle',
                'color' => $isActive === 'true' ? 'bg-blue' : 'bg-aqua',
            ],
            [
                'count' => '$'.\App\Helpers\Helper::formatCurrencyOutput($totalCost),
                'label' => trans('admin/contracts/general.tile_total_spend'),
                'href'  => null,
                'icon'  => 'fa-wallet',
                'color' => 'bg-navy',
            ],
            [
                'count' => number_format($expiring30),
                'label' => trans('admin/contracts/general.tile_expiring_30'),
                'href'  => $link(['expiring_within_days' => 30]),
                'icon'  => 'fa-hourglass-end',
                'color' => (string) $expiringDays === '30' ? 'bg-blue' : ($expiring30 > 0 ? 'bg-red' : 'bg-green'),
            ],
            [
                'count' => number_format($expiring90),
                'label' => trans('admin/contracts/general.tile_expiring_90'),
                'href'  => $link(['expiring_within_days' => 90]),
                'icon'  => 'fa-calendar-alt',
                'color' => (string) $expiringDays === '90' ? 'bg-blue' : ($expiring90 > 0 ? 'bg-yellow' : 'bg-aqua'),
            ],
            [
                'count' => number_format($renewalSeriesCount),
                'label' => trans('admin/contracts/general.tile_renewal_series'),
                'href'  => $link(['synthesized_only' => 'true']),
                'icon'  => 'fa-layer-group',
                'color' => $seriesOnly ? 'bg-blue' : 'bg-purple',
            ],
        ];

        $reports = [
            ['route' => 'contracts.reports.expiring-soon',    'name' => 'report_expiring_soon_title',    'desc' => 'report_expiring_soon_desc',    'params' => ['days' => 90]],
            ['route' => 'contracts.reports.renewal-series',   'name' => 'report_renewal_series_title',   'desc' => 'report_renewal_series_desc',   'params' => []],
            ['route' => 'contracts.reports.by-area',          'name' => 'report_by_area_title',          'desc' => 'report_by_area_desc',          'params' => []],
            ['route' => 'contracts.reports.by-provider',      'name' => 'report_by_provider_title',      'desc' => 'report_by_provider_desc',      'params' => []],
            ['route' => 'contracts.reports.serial-register',  'name' => 'report_serial_register_title',  'desc' => 'report_serial_register_desc',  'params' => []],
            ['route' => 'contracts.reports.naming-violators', 'name' => 'report_naming_violators_title', 'desc' => 'report_naming_violators_desc', 'params' => []],
            ['route' => 'contracts.reports.stale',            'name' => 'report_stale_title',            'desc' => 'report_stale_desc',            'params' => []],
        ];
    @endphp

    <x-container>

        {{-- Scope bar: the fiscal year and the filters read as one control
             strip. No page title here — the breadcrumb above already says
             Contracts, and repeating it just pushed the tiles down. --}}
        <div class="row">
            <div class="col-md-12">
                <div class="contracts-scopebar">
                    {{-- Switching FY reloads the page; stash the scroll position
                         so the reader lands back where they were. --}}
                    <form method="get" style="margin:0;">
                        @foreach ($tileFilters as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <select name="fiscal_year" class="form-control input-sm" style="width:auto;"
                                onchange="sessionStorage.setItem('contractsScroll', String(window.scrollY)); this.form.submit()">
                            <option value="all" {{ $selectedFy === null ? 'selected' : '' }}>{{ trans('admin/contracts/general.all_fiscal_years') }}</option>
                            @foreach ($allFiscalYears as $fy)
                                <option value="{{ $fy }}" {{ $selectedFy === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                            @endforeach
                        </select>
                    </form>

                    @if ($themes->isNotEmpty())
                        <span class="contracts-filter-label">{{ trans('admin/contracts/general.filter_by_area') }}:</span>
                        @foreach ($themes as $theme)
                            <a href="{{ $link(['theme' => $selectedTheme === $theme->theme ? null : $theme->theme]) }}"
                               class="btn btn-xs {{ $selectedTheme === $theme->theme ? 'btn-primary' : 'btn-default' }}">
                                {{ $theme->theme }} <span class="badge">{{ $theme->n }}</span>
                            </a>
                        @endforeach
                    @endif

                    {{-- Replaces the old "Umbrellas" tile. As a count it meant
                         little — it mixed standalone contracts in with the
                         renewal-series rows — but as a filter it does the useful
                         thing: collapse each series to one row. --}}
                    <a href="{{ $link(array_merge($tileFilters, ['top_level' => $topLevelOnly ? null : 'true'])) }}"
                       class="btn btn-xs {{ $topLevelOnly ? 'btn-primary' : 'btn-default' }}"
                       data-tooltip="true" title="{{ trans('admin/contracts/general.filter_top_level_help') }}">
                        <i class="fas {{ $topLevelOnly ? 'fa-check-square' : 'fa-square' }}" aria-hidden="true"></i>
                        {{ trans('admin/contracts/general.filter_top_level') }}
                    </a>

                    @unless ($noFilter)
                        <a href="{{ $link() }}" class="btn btn-xs btn-link">
                            {{ trans('general.clear_filters') }}
                        </a>
                    @endunless
                </div>
            </div>
        </div>

        {{-- KPI tiles. Each one is a filter over the table at the bottom of
             the page, so its number is exactly what clicking it leaves
             behind — except Total spend, which is a figure, not a filter. --}}
        <div class="row contracts-tile-row">
            @foreach ($tiles as $tile)
                <div class="col-md-2 col-sm-4 col-xs-6">
                    @if ($tile['href'])
                        <a href="{{ $tile['href'] }}" class="small-box-link">
                    @endif
                        <div class="small-box {{ $tile['color'] }}">
                            <div class="inner">
                                <h3 style="font-size: 22px">{{ $tile['count'] }}</h3>
                                <p>{{ $tile['label'] }}</p>
                            </div>
                            <div class="icon"><i class="fas {{ $tile['icon'] }}" aria-hidden="true"></i></div>
                        </div>
                    @if ($tile['href'])
                        </a>
                    @endif
                </div>
            @endforeach
        </div>


        @if ($charts)
            {{-- Four across on one row. Every chart reads against the filters
                 in the scope bar above, so a theme or an expiry window
                 redraws these as well as the table. --}}
            <div class="row contracts-chart-row">
                @foreach ([
                    ['id' => 'contractsFyChart',       'title' => 'chart_spend_by_fy'],
                    ['id' => 'contractsProviderChart', 'title' => 'chart_provider'],
                    ['id' => 'contractsThemeChart',    'title' => 'chart_theme'],
                    ['id' => 'contractsRenewalChart',  'title' => 'chart_renewals'],
                ] as $chart)
                    <div class="col-md-3 col-sm-6">
                        <div class="box box-default">
                            <div class="box-header with-border">
                                <h3 class="box-title">{{ trans('admin/contracts/general.'.$chart['title']) }}</h3>
                            </div>
                            <div class="box-body">
                                <div style="position:relative; height:260px;">
                                    <canvas id="{{ $chart['id'] }}"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- The register itself, directly under the charts: everything above
             narrows it. --}}
        <x-box>
            <x-table.contracts
                fixed_right_number="1"
                fixed_number="1"
                show_advanced_search="true"
                name="contracts"
                :route="route('api.contracts.index', $apiParams)"
            />
        </x-box>

        @if ($charts)
            {{-- The drill-downs, one at a time across the full width. Each is
                 also a page of its own at /contracts/<report> — the Open
                 button goes there — so a single report stays linkable. Only
                 the visible tab is fetched; the rest load when first
                 selected, so opening the page costs one query, not seven. --}}
            <div class="row">
                <div class="col-md-12">
                    <h3 class="contracts-reports-heading">{{ trans('admin/contracts/general.reports') }}</h3>
                    <x-tabs>
                        <x-slot:tabnav>
                            @foreach ($reports as $report)
                                <x-tabs.nav-item
                                    name="rpt-{{ $report['name'] }}"
                                    label="{{ trans('admin/contracts/general.'.$report['name']) }}"
                                    class="{{ $loop->first ? 'active' : '' }}"
                                />
                            @endforeach
                        </x-slot:tabnav>

                        <x-slot:tabpanes>
                            @foreach ($reports as $report)
                                <x-tabs.pane name="rpt-{{ $report['name'] }}" class="{{ $loop->first ? 'active in' : '' }}">
                                    <div class="contracts-report-head">
                                        <p class="text-muted" style="margin:0;">{{ trans('admin/contracts/general.'.$report['desc']) }}</p>
                                        <span class="contracts-report-actions">
                                            <a href="{{ route($report['route'], $report['params']) }}" class="btn btn-sm btn-default">
                                                <x-icon type="reports" /> {{ trans('general.view') }}
                                            </a>
                                            <a href="{{ route($report['route'], array_merge($report['params'], ['format' => 'csv'])) }}" class="btn btn-sm btn-default">
                                                <x-icon type="download" /> {{ trans('general.download') }}
                                            </a>
                                        </span>
                                    </div>
                                    <div class="contracts-report-body" data-embed-url="{{ route($report['route'], array_merge($report['params'], ['embed' => 1])) }}">
                                        <div class="text-center text-muted" style="padding:18px;">
                                            <i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                                        </div>
                                    </div>
                                </x-tabs.pane>
                            @endforeach
                        </x-slot:tabpanes>
                    </x-tabs>
                </div>
            </div>
        @endif
    </x-container>

    <style>
        /* Each report's description sits left, its Open/Download buttons
           right, above the full-width table. */
        .contracts-report-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 10px;
        }
        .contracts-report-actions { white-space: nowrap; }

        /* ── Frozen table headings ───────────────────────────────────────
           position:sticky is killed by any scrolling ancestor, so the page
           has to be the scroller: AdminLTE clips .wrapper/.content-wrapper,
           and Bootstrap's .table-responsive ships overflow-x:auto at every
           width. Both are lifted here, and only narrow screens fall back to
           the table's own scroller — a wide table must not force the whole
           page to scroll sideways. */
        .wrapper, .content-wrapper { overflow: visible !important; }

        @media (min-width: 992px) {
            .snipetab-pane .table-responsive { overflow: visible; }
        }
        @media (max-width: 991px) {
            .snipetab-pane .table-responsive {
                overflow: auto;
                max-height: calc(100vh - var(--header-h, 68px) - 160px);
            }
        }

        /* The register's heads pin below the toolbar the layout already
           sticks under the app bar; the offset is measured live in
           stickyHeads(). The opaque background is what stops rows showing
           through as they pass underneath. This block only ships with this
           page, so the selectors need no extra scoping. */
        .snipe-table thead th,
        .snipetab-pane .table thead th {
            position: sticky;
            top: var(--contracts-thead-top, var(--header-h, 68px));
            z-index: 5;
            background: var(--box-bg, #fff);
            box-shadow: 0 1px 0 var(--box-header-top-border-color, #d2d6de);
        }

        /* The rounded box does not clip its children, so a pinned thead with
           an opaque background overdraws the corner radius. clip is not a
           scroll container, so sticky keeps tracking the page. Applied to the
           tab container only — the register box holds the toolbar's column
           dropdown, which has to be able to escape it. */
        .nav-tabs-custom { overflow: clip; }
        /* Scope bar: FY picker and the filters on one line, wrapping as a
           unit on narrow viewports rather than overflowing. */
        .contracts-scopebar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            margin-bottom: 12px;
        }
        .contracts-filter-label { margin-left: 8px; color: var(--color-fg-muted, #666); }
        /* Bootstrap's .badge is inline-block with its own line-height, which
           sits the count a couple of pixels below the label's baseline inside
           a btn-xs. Centre both on a shared flex line instead. */
        .contracts-scopebar .btn-xs {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .contracts-scopebar .btn-xs > .badge {
            line-height: 1;
            padding: 3px 6px;
            position: static;
            top: auto;
        }
        /* Four charts across: keep the boxes equal height so the row does not
           step when one chart's legend wraps. */
        .contracts-chart-row { display: flex; flex-wrap: wrap; }
        .contracts-chart-row > [class*="col-"] { display: flex; margin-bottom: 15px; }
        .contracts-chart-row .box { width: 100%; margin-bottom: 0; }
        /* Double the breathing room between the page's bands — tiles, charts,
           register, reports — so each reads as its own block rather than one
           continuous stack. */
        .contracts-tile-row { margin-bottom: 15px; }
        .contracts-chart-row { margin-bottom: 15px; }
        .contracts-chart-row + .box,
        .contracts-chart-row ~ .row { margin-top: 30px; }
        .contracts-reports-heading { margin: 30px 0 10px; font-size: 18px; }
        /* Equal-height tiles so a wrapped label never reflows the grid. */
        .contracts-tile-row { display: flex; flex-wrap: wrap; }
        .contracts-tile-row > [class*="col-"] { display: flex; margin-bottom: 15px; }
        .contracts-tile-row .small-box-link { display: flex; width: 100%; }
        .contracts-tile-row .small-box { width: 100%; min-height: 104px; margin-bottom: 0; display: flex; flex-direction: column; justify-content: center; }
        .contracts-tile-row .small-box > .inner { width: 100%; }
    </style>
@stop

@section('moar_scripts')
@include('partials.bootstrap-table')
@if ($charts)
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
    (function () {
        // Restore the scroll position after an FY switch.
        var stashed = sessionStorage.getItem('contractsScroll');
        if (stashed !== null) {
            sessionStorage.removeItem('contractsScroll');
            window.addEventListener('load', function () { window.scrollTo(0, Number(stashed)); });
        }

        if (typeof Chart === 'undefined') { return; }

        var data = {!! json_encode($charts) !!};

        var money = function (v) {
            return '$' + Number(v).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        };
        var moneyAxis = function () { return { ticks: { beginAtZero: true, callback: money } }; };
        var barTip = { callbacks: { label: function (i, d) { return d.datasets[i.datasetIndex].label + ': ' + money(i.yLabel); } } };
        var pieTip = { callbacks: { label: function (i, d) { return d.labels[i.index] + ': ' + money(d.datasets[0].data[i.index]); } } };

        new Chart(document.getElementById('contractsFyChart'), {
            type: 'bar',
            data: {
                labels: data.fyLabels,
                datasets: [
                    { label: @json(trans('admin/contracts/general.total_cost')), backgroundColor: '#3c8dbc', data: data.fyValues }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTip, scales: { yAxes: [moneyAxis()] } }
        });

        new Chart(document.getElementById('contractsProviderChart'), {
            type: 'doughnut',
            data: {
                labels: data.providerLabels,
                datasets: [{
                    backgroundColor: ['#00c0ef', '#f39c12', '#00a65a', '#dd4b39', '#605ca8', '#39cccc', '#ff851b', '#d81b60', '#3c8dbc', '#001f3f'],
                    data: data.providerValues
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: pieTip }
        });

        // The theme chart keeps every bar even when a theme filter is on —
        // filtering it by its own axis would leave a single bar — so the
        // selected one is picked out by colour instead.
        new Chart(document.getElementById('contractsThemeChart'), {
            type: 'horizontalBar',
            data: {
                labels: data.themeLabels,
                datasets: [{
                    label: @json(trans('general.count')),
                    backgroundColor: data.themeLabels.map(function (t) {
                        return (data.themeSelected && t !== data.themeSelected) ? 'rgba(0,166,90,0.35)' : '#00a65a';
                    }),
                    data: data.themeValues
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { xAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] } }
        });

        new Chart(document.getElementById('contractsRenewalChart'), {
            type: 'line',
            data: {
                labels: data.renewalLabels,
                datasets: [{
                    label: @json(trans('admin/contracts/general.chart_renewals')),
                    borderColor: '#dd4b39',
                    backgroundColor: 'rgba(221,75,57,0.15)',
                    data: data.renewalValues
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] } }
        });
    })();

    // Every report loads on page open so switching tabs is instant. They go
    // one at a time rather than seven in parallel — the serial register is a
    // heavy query and a stampede just makes them all slower — and the tab
    // handler stays wired to pick up anything that failed or hasn't landed
    // by the time it is clicked.
    (function () {
        function load(el) {
            if (! el || el.dataset.loaded) { return Promise.resolve(); }
            el.dataset.loaded = '1';

            return fetch(el.dataset.embedUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (resp) {
                    if (! resp.ok) { throw new Error('HTTP ' + resp.status); }
                    return resp.text();
                })
                .then(function (html) {
                    el.innerHTML = html;
                    stickyHeads();
                })
                .catch(function () {
                    delete el.dataset.loaded;
                    el.innerHTML = '<p class="text-danger">' + @json(trans('general.something_went_wrong')) + '</p>';
                });
        }

        $('a[data-toggle="tab"][href^="#rpt-"]').on('shown.bs.tab', function (e) {
            var pane = document.querySelector(e.target.getAttribute('href'));
            load(pane && pane.querySelector('.contracts-report-body[data-embed-url]'));
        });

        $(function () {
            // Visible tab first so it is ready soonest, then the rest in order.
            var active = document.querySelector('.snipetab-pane.active[id^="rpt-"]');
            var bodies = Array.prototype.slice.call(
                document.querySelectorAll('.contracts-report-body[data-embed-url]')
            );
            if (active) {
                var first = active.querySelector('.contracts-report-body[data-embed-url]');
                bodies = [first].concat(bodies.filter(function (b) { return b !== first; }));
            }

            bodies.reduce(function (chain, el) {
                return chain.then(function () { return load(el); });
            }, Promise.resolve());
        });
    })();

    // Frozen table headings. The layout pins the bootstrap-table toolbar
    // under the app bar, so a thead left unpinned lets rows scroll up
    // underneath it — which is the strip of background that reads as a bar
    // across the top of the table. Pin the heads too, directly below the
    // toolbar, measuring its height live because it wraps at narrow widths.
    function stickyHeads() {
        var headerVar = getComputedStyle(document.documentElement).getPropertyValue('--header-h');
        var headerH = parseInt(headerVar, 10);
        if (isNaN(headerH)) { headerH = 68; }

        document.querySelectorAll('.bootstrap-table').forEach(function (table) {
            var toolbar = table.querySelector('.fixed-table-toolbar');
            var offset = headerH + (toolbar ? toolbar.offsetHeight : 0);
            table.style.setProperty('--contracts-thead-top', offset + 'px');
        });

        document.querySelectorAll('.snipetab-pane').forEach(function (pane) {
            pane.style.setProperty('--contracts-thead-top', headerH + 'px');
        });
    }

    window.addEventListener('resize', function () { requestAnimationFrame(stickyHeads); }, { passive: true });
    window.addEventListener('load', stickyHeads);
    $(function () { stickyHeads(); });
</script>
@endif
@stop
