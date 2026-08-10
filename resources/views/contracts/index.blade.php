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
            ['route' => 'contracts.reports.by-theme',         'name' => 'report_by_theme_title',         'desc' => 'report_by_theme_desc',         'params' => []],
            ['route' => 'contracts.reports.by-provider',      'name' => 'report_by_provider_title',      'desc' => 'report_by_provider_desc',      'params' => []],
            ['route' => 'contracts.reports.serial-register',  'name' => 'report_serial_register_title',  'desc' => 'report_serial_register_desc',  'params' => []],
            ['route' => 'contracts.reports.naming-violators', 'name' => 'report_naming_violators_title', 'desc' => 'report_naming_violators_desc', 'params' => []],
            ['route' => 'contracts.reports.stale',            'name' => 'report_stale_title',            'desc' => 'report_stale_desc',            'params' => []],
        ];
    @endphp

    <x-container>

        <div class="row">
            <div class="col-md-12">
                <div class="box-header" style="padding-left:0;">
                    <h1 class="box-title" style="font-size:22px; margin:0; display:inline-block; vertical-align:middle;">
                        {{ trans('admin/contracts/general.contracts') }}
                    </h1>
                    {{-- Switching FY reloads the page; stash the scroll position
                         so the reader lands back where they were. --}}
                    <form method="get" style="display:inline-block; margin-left:15px; vertical-align:middle;">
                        @foreach ($tileFilters as $key => $value)
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endforeach
                        <select name="fiscal_year" class="form-control input-sm" style="display:inline-block; width:auto;"
                                onchange="sessionStorage.setItem('contractsScroll', String(window.scrollY)); this.form.submit()">
                            <option value="">{{ trans('admin/contracts/general.all_fiscal_years') }}</option>
                            @foreach ($allFiscalYears as $fy)
                                <option value="{{ $fy }}" {{ $selectedFy === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                            @endforeach
                        </select>
                    </form>
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

        <div class="row" style="margin-bottom: 10px;">
            <div class="col-md-12">
                @if ($themes->isNotEmpty())
                    <span style="margin-right: 8px; color: #666;">{{ trans('admin/contracts/general.filter_by_theme') }}:</span>
                    @foreach ($themes as $theme)
                        <a href="{{ $link(['theme' => $theme->theme]) }}"
                           class="btn btn-xs {{ $selectedTheme === $theme->theme ? 'btn-primary' : 'btn-default' }}"
                           style="margin-right: 4px; margin-bottom: 4px;">
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
                   style="margin-left: 8px; margin-bottom: 4px;"
                   data-tooltip="true" title="{{ trans('admin/contracts/general.filter_top_level_help') }}">
                    <i class="fas {{ $topLevelOnly ? 'fa-check-square' : 'fa-square' }}" aria-hidden="true"></i>
                    {{ trans('admin/contracts/general.filter_top_level') }}
                </a>
                @unless ($noFilter)
                    <a href="{{ $link() }}" class="btn btn-xs btn-link" style="margin-bottom: 4px;">
                        {{ trans('general.clear_filters') }}
                    </a>
                @endunless
            </div>
        </div>

        @if ($charts)
            <div class="row">
                <div class="col-md-7">
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">{{ trans('admin/contracts/general.chart_spend_by_fy') }}</h3>
                        </div>
                        <div class="box-body">
                            <div style="position:relative; height:300px;">
                                <canvas id="contractsFyChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">{{ trans('admin/contracts/general.chart_provider') }}</h3>
                        </div>
                        <div class="box-body">
                            <div style="position:relative; height:300px;">
                                <canvas id="contractsProviderChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">{{ trans('admin/contracts/general.chart_theme') }}</h3>
                        </div>
                        <div class="box-body">
                            <div style="position:relative; height:280px;">
                                <canvas id="contractsThemeChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">{{ trans('admin/contracts/general.chart_renewals') }}</h3>
                        </div>
                        <div class="box-body">
                            <div style="position:relative; height:280px;">
                                <canvas id="contractsRenewalChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- The drill-downs, rendered inline. Each is also a page of its
                 own at /contracts/<report> — the title and the view button
                 both go there — so a single report stays linkable. --}}
            <div class="row contracts-reports-row">
                <div class="contracts-nav-col hidden-sm hidden-xs">
                    <div class="contracts-report-nav">
                        <div class="box box-default" style="margin-bottom:0;">
                            <div class="box-header with-border">
                                <h3 class="box-title">{{ trans('admin/contracts/general.reports') }}</h3>
                            </div>
                            <div class="box-body no-padding">
                                <ul class="nav nav-pills nav-stacked contracts-report-navlist">
                                    @foreach ($reports as $report)
                                        <li>
                                            <a href="#rpt-{{ $report['name'] }}" data-target-report="rpt-{{ $report['name'] }}">
                                                {{ trans('admin/contracts/general.'.$report['name']) }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="contracts-content-col col-sm-12">
                    @foreach ($reports as $report)
                        <div class="box box-default contracts-report-box" id="rpt-{{ $report['name'] }}" style="scroll-margin-top:64px;">
                            <div class="box-header with-border">
                                <h3 class="box-title">
                                    <a href="{{ route($report['route'], $report['params']) }}">{{ trans('admin/contracts/general.'.$report['name']) }}</a>
                                </h3>
                                <div class="box-tools pull-right">
                                    <a href="{{ route($report['route'], $report['params']) }}" class="btn btn-box-tool" data-tooltip="true" title="{{ trans('general.view') }}">
                                        <x-icon type="reports" />
                                    </a>
                                    <a href="{{ route($report['route'], array_merge($report['params'], ['format' => 'csv'])) }}" class="btn btn-box-tool" data-tooltip="true" title="{{ trans('general.download') }}">
                                        <x-icon type="download" />
                                    </a>
                                    <button type="button" class="btn btn-box-tool" data-widget="collapse" data-tooltip="true" title="{{ trans('general.collapse') }}">
                                        <i class="fa fa-minus" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <p class="text-muted contracts-report-desc">{{ trans('admin/contracts/general.'.$report['desc']) }}</p>
                            </div>
                            <div class="box-body">
                                <div class="contracts-report-body" data-embed-url="{{ route($report['route'], array_merge($report['params'], ['embed' => 1])) }}">
                                    <div class="text-center text-muted" style="padding:18px;">
                                        <i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- The register itself, last: everything above narrows it. --}}
        <x-box>
            <x-table.contracts
                fixed_right_number="1"
                fixed_number="1"
                show_advanced_search="true"
                name="contracts"
                :route="route('api.contracts.index', $apiParams)"
            />
        </x-box>
    </x-container>

    <style>
        /* AdminLTE wraps the page in `.wrapper { overflow: hidden }`, and an
           overflow ancestor traps position:sticky — the jump-nav would just
           scroll away. Lift the clip on this page so sticky resolves against
           the body scroll. */
        .wrapper, .content-wrapper { overflow: visible !important; }
        /* Flex row so the nav column stretches to the full height of the
           reports column; that's what gives sticky room to stay pinned. */
        .contracts-reports-row { display: flex; flex-wrap: wrap; }
        .contracts-reports-row .contracts-nav-col { flex: 0 0 16%; max-width: 16%; padding-left: 15px; padding-right: 15px; }
        .contracts-reports-row .contracts-content-col { flex: 1 1 0%; max-width: 84%; padding-left: 15px; padding-right: 15px; }
        .contracts-report-nav {
            position: sticky;
            top: calc(var(--header-h, 68px) + 12px);
            max-height: calc(100vh - var(--header-h, 68px) - 24px);
            overflow-y: auto;
        }
        /* AdminLTE's .box .nav-stacked ships hard-coded light values (#f4f4f4
           borders, #444 links) — white hairlines and near-black text in dark
           mode. Key both on the theme tokens. */
        .contracts-report-navlist > li { border-bottom: 1px solid var(--box-header-bottom-border-color, #f4f4f4) !important; }
        .contracts-report-navlist > li:last-child { border-bottom: 0 !important; }
        .contracts-report-navlist > li > a { padding: 6px 10px; font-size: 12.5px; border-radius: 0; color: var(--color-fg, #444) !important; }
        .contracts-report-navlist > li.active > a,
        .contracts-report-navlist > li.active > a:hover { background-color: #3c8dbc; color: #fff !important; }
        .contracts-report-box > .box-header {
            position: sticky;
            top: var(--header-h, 68px);
            z-index: 8;
            background: var(--box-bg, #fff);
        }
        .contracts-report-desc { margin: 8px 0 0; font-size: 12.5px; clear: both; }
        @media (max-width: 991px) {
            .contracts-reports-row { display: block; }
            .contracts-reports-row .contracts-content-col { max-width: 100%; }
        }
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

        new Chart(document.getElementById('contractsThemeChart'), {
            type: 'horizontalBar',
            data: {
                labels: data.themeLabels,
                datasets: [{ label: @json(trans('general.count')), backgroundColor: '#00a65a', data: data.themeValues }]
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

    // Lazy-load each report's table inline. Loaded one at a time (rather than
    // seven parallel queries) so the heavy ones — the serial register above
    // all — don't stampede the server; each section fills in as it arrives.
    (function () {
        var pending = Array.prototype.slice.call(
            document.querySelectorAll('.contracts-report-body[data-embed-url]')
        );

        function loadNext() {
            var el = pending.shift();
            if (! el) { return; }

            fetch(el.dataset.embedUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (resp) {
                    if (! resp.ok) { throw new Error('HTTP ' + resp.status); }
                    return resp.text();
                })
                .then(function (html) { el.innerHTML = html; })
                .catch(function () {
                    el.innerHTML = '<p class="text-danger">' + @json(trans('general.something_went_wrong')) + '</p>';
                })
                .then(loadNext);
        }

        loadNext();
    })();

    // Highlight the report currently in view in the sticky jump-nav.
    (function () {
        var links = {};
        document.querySelectorAll('.contracts-report-navlist a[data-target-report]').forEach(function (a) {
            links[a.dataset.targetReport] = a.parentElement;
        });
        var boxes = document.querySelectorAll('.contracts-report-box');
        if (! boxes.length || typeof IntersectionObserver === 'undefined') { return; }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var li = links[entry.target.id];
                if (! li) { return; }
                if (entry.isIntersecting) {
                    Object.keys(links).forEach(function (k) { links[k].classList.remove('active'); });
                    li.classList.add('active');
                }
            });
        }, { rootMargin: '-64px 0px -70% 0px' });

        boxes.forEach(function (box) { observer.observe(box); });
    })();
</script>
@endif
@stop
