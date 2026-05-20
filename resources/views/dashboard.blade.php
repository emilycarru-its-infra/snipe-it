@extends('layouts/default')

@section('title')
{{ trans('general.dashboard') }}
@parent
@stop

@section('content')

@if ($snipeSettings->dashboard_message!='')
    <div class="row">
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-body">
                    {!! Helper::parseEscapedMarkedown($snipeSettings->dashboard_message) !!}
                </div>
            </div>
        </div>
    </div>
@endif

@if ($counts['grand_total'] == 0)

    {{-- Empty state: identical to the original Snipe onboarding pane. --}}
    <div class="row">
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('general.dashboard_info') }}</h2>
                </div>
                <div class="box-body">
                    <p><strong>{{ trans('general.dashboard_empty') }}</strong></p>
                    <div class="row" style="margin-top: 10px;">
                        @can('create', \App\Models\Asset::class)
                            <div class="col-md-2"><a class="btn bg-teal" style="width: 100%" href="{{ route('hardware.create') }}">{{ trans('general.new_asset') }}</a></div>
                        @endcan
                        @can('create', \App\Models\License::class)
                            <div class="col-md-2"><a class="btn bg-maroon" style="width: 100%" href="{{ route('licenses.create') }}">{{ trans('general.new_license') }}</a></div>
                        @endcan
                        @can('create', \App\Models\Accessory::class)
                            <div class="col-md-2"><a class="btn bg-orange" style="width: 100%" href="{{ route('accessories.create') }}">{{ trans('general.new_accessory') }}</a></div>
                        @endcan
                        @can('create', \App\Models\Consumable::class)
                            <div class="col-md-2"><a class="btn bg-purple" style="width: 100%" href="{{ route('consumables.create') }}">{{ trans('general.new_consumable') }}</a></div>
                        @endcan
                        @can('create', \App\Models\Component::class)
                            <div class="col-md-2"><a class="btn bg-yellow" style="width: 100%" href="{{ route('components.create') }}">{{ trans('general.new_component') }}</a></div>
                        @endcan
                        @can('create', \App\Models\User::class)
                            <div class="col-md-2"><a class="btn bg-light-blue" style="width: 100%" href="{{ route('users.create') }}">{{ trans('general.new_user') }}</a></div>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>

@else

{{-- ───────────────────── ROW 1: KPI strip ───────────────────── --}}
@php
    $delta = $kpis['created_last_7'] - $kpis['created_prev_7'];
    $deltaSign = $delta > 0 ? '+' : '';
@endphp
<div class="row dashboard-kpi-strip">
    <div class="col-md-12">
        <div class="kpi-grid">

            <a class="kpi-tile kpi-teal" href="{{ route('hardware.index') }}">
                <div class="kpi-value">{{ number_format($kpis['total']) }}</div>
                <div class="kpi-label">Total Assets</div>
                <div class="kpi-sub">{{ $deltaSign }}{{ $delta }} this week</div>
            </a>

            <a class="kpi-tile kpi-green" href="{{ route('hardware.index') }}?status=Deployed">
                <div class="kpi-value">{{ number_format($kpis['deployed']) }}</div>
                <div class="kpi-label">Deployed</div>
                <div class="kpi-sub">{{ $kpis['total'] ? round($kpis['deployed'] / $kpis['total'] * 100) : 0 }}% of fleet</div>
            </a>

            <a class="kpi-tile kpi-blue" href="{{ route('hardware.index') }}?status=RTD">
                <div class="kpi-value">{{ number_format($kpis['ready_to_deploy']) }}</div>
                <div class="kpi-label">Ready to Deploy</div>
                <div class="kpi-sub">available pool</div>
            </a>

            <a class="kpi-tile kpi-aqua" href="{{ route('hardware.index') }}?status=RTD">
                <div class="kpi-value">{{ number_format($kpis['not_checked_out']) }}</div>
                <div class="kpi-label">Not Checked Out</div>
                <div class="kpi-sub">unassigned</div>
            </a>

            <a class="kpi-tile kpi-yellow" href="{{ route('hardware.index') }}?status=Pending">
                <div class="kpi-value">{{ number_format($kpis['pending']) }}</div>
                <div class="kpi-label">Pending</div>
                <div class="kpi-sub">awaiting processing</div>
            </a>

            <a class="kpi-tile kpi-red" href="{{ route('hardware.index') }}?status=Damaged">
                <div class="kpi-value">{{ number_format($kpis['damaged_missing']) }}</div>
                <div class="kpi-label">Damaged + Missing</div>
                <div class="kpi-sub">needs attention</div>
            </a>

            <a class="kpi-tile kpi-purple" href="{{ route('assets.audit.due') }}">
                <div class="kpi-value">{{ number_format($kpis['due_audit'] + $kpis['overdue_audit']) }}</div>
                <div class="kpi-label">Due for Audit</div>
                <div class="kpi-sub">{{ $kpis['overdue_audit'] }} overdue</div>
            </a>

            <a class="kpi-tile kpi-orange" href="{{ route('assets.checkins.due') }}">
                <div class="kpi-value">{{ number_format($kpis['due_checkin']) }}</div>
                <div class="kpi-label">Due for Checkin</div>
                <div class="kpi-sub">{{ $kpis['in_progress_maintenance'] }} maint. in-progress</div>
            </a>

        </div>
    </div>
</div>

{{-- ───────────────────── ROW 2: Lifecycle funnel ───────────────────── --}}
@php
    $lifecycleTotal = collect($lifecycle)->sum('count');
@endphp
@if ($lifecycleTotal > 0)
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">Asset Lifecycle</h2>
            </div>
            <div class="box-body">
                <div class="lifecycle-bar" role="img" aria-label="Asset lifecycle distribution">
                    @foreach ($lifecycle as $stageKey => $stage)
                        @if ($stage['count'] > 0)
                            @php $pct = $stage['count'] / $lifecycleTotal * 100; @endphp
                            <div class="lifecycle-segment"
                                 style="width: {{ $pct }}%; background: {{ $stage['color'] }};"
                                 title="{{ $stage['label'] }} — {{ number_format($stage['count']) }} ({{ round($pct, 1) }}%)">
                                @if ($pct >= 4)
                                    <span class="lifecycle-segment-label">{{ number_format($stage['count']) }}</span>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
                <div class="lifecycle-legend">
                    @foreach ($lifecycle as $stageKey => $stage)
                        @if ($stage['count'] > 0)
                            <div class="lifecycle-stage">
                                <div class="lifecycle-stage-header">
                                    <span class="lifecycle-swatch" style="background: {{ $stage['color'] }};"></span>
                                    <span class="lifecycle-stage-name">{{ $stage['label'] }}</span>
                                    <span class="lifecycle-stage-count">{{ number_format($stage['count']) }}</span>
                                </div>
                                <ul class="lifecycle-items">
                                    @foreach ($stage['items'] as $item)
                                        @if ($item['count'] > 0)
                                            <li>
                                                <a href="{{ route('hardware.index') }}?status_id={{ $item['id'] }}">
                                                    {{ $item['name'] }}
                                                    <span class="badge">{{ number_format($item['count']) }}</span>
                                                </a>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ───────────────────── ROW 3: Recent activity (75%) + Procurement (25%) ───────────────────── --}}
<div class="row">
    <div class="col-md-{{ $procurement ? 9 : 12 }}">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('general.recent_activity') }}</h2>
                <div class="box-tools pull-right">
                    <button type="button" class="btn btn-box-tool" data-widget="collapse" aria-hidden="true">
                        <x-icon type="minus" />
                        <span class="sr-only">{{ trans('general.collapse') }}</span>
                    </button>
                </div>
            </div>
            <div class="box-body">
                <table
                    data-cookie-id-table="dashActivityReport"
                    data-height="380"
                    data-pagination="false"
                    data-side-pagination="server"
                    data-id-table="dashActivityReport"
                    data-sort-order="desc"
                    data-show-columns="false"
                    data-fixed-number="false"
                    data-fixed-right-number="false"
                    data-sort-name="created_at"
                    id="dashActivityReport"
                    class="table table-striped snipe-table"
                    data-url="{{ route('api.activity.index', ['limit' => 10]) }}">
                    <thead>
                    <tr>
                        <th data-field="icon" data-visible="true" style="width: 40px;" class="hidden-xs" data-formatter="iconFormatter"><span class="sr-only">{{ trans('admin/hardware/table.icon') }}</span></th>
                        <th class="col-sm-2" data-visible="true" data-field="created_at" data-formatter="dateDisplayFormatter">{{ trans('general.date') }}</th>
                        <th class="col-sm-2" data-visible="true" data-field="admin" data-formatter="usersLinkObjFormatter">{{ trans('general.created_by') }}</th>
                        <th class="col-sm-2" data-visible="true" data-field="action_type">{{ trans('general.action') }}</th>
                        <th class="col-sm-3" data-visible="true" data-field="item" data-formatter="polymorphicItemFormatter">{{ trans('general.item') }}</th>
                        <th class="col-sm-3" data-visible="true" data-field="target" data-formatter="polymorphicItemFormatter">{{ trans('general.target') }}</th>
                    </tr>
                    </thead>
                </table>
                <div class="text-center" style="padding-top: 10px;">
                    <a href="{{ route('reports.activity') }}" class="btn btn-theme btn-sm" style="width: 100%">{{ trans('general.viewall') }}</a>
                </div>
            </div>
        </div>
    </div>

    @if ($procurement)
    <div class="col-md-3">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">Procurement</h2>
            </div>
            <div class="box-body procurement-card">

                <div class="procurement-mini-stats">
                    <a href="{{ route('purchase-orders.index') }}?status=open" class="procurement-stat">
                        <span class="procurement-stat-value">{{ number_format($procurement['open_po_count']) }}</span>
                        <span class="procurement-stat-label">Open POs</span>
                    </a>
                    <a href="{{ route('orders.index') }}" class="procurement-stat">
                        <span class="procurement-stat-value">{{ number_format($procurement['open_orders_count']) }}</span>
                        <span class="procurement-stat-label">Open Orders</span>
                    </a>
                    <a href="{{ url('order-invoices?unmatched=1') }}" class="procurement-stat">
                        <span class="procurement-stat-value {{ $procurement['unmatched_invoices'] > 0 ? 'procurement-warn' : '' }}">{{ number_format($procurement['unmatched_invoices']) }}</span>
                        <span class="procurement-stat-label">Unmatched Invoices</span>
                    </a>
                </div>

                <h4 class="procurement-section-title">Open Orders</h4>
                @if ($procurement['open_orders']->isEmpty())
                    <p class="procurement-empty">No open orders.</p>
                @else
                    <ul class="procurement-list">
                        @foreach ($procurement['open_orders'] as $order)
                            <li>
                                <a href="{{ route('orders.show', $order->id) }}">
                                    <span class="po-number">{{ $order->order_number }}</span>
                                    <span class="po-supplier">{{ $order->supplier_name ?: '—' }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <h4 class="procurement-section-title">Recent Orders</h4>
                @if ($procurement['recent_orders']->isEmpty())
                    <p class="procurement-empty">No recent activity.</p>
                @else
                    <ul class="procurement-list">
                        @foreach ($procurement['recent_orders'] as $order)
                            <li>
                                <a href="{{ route('orders.show', $order->id) }}">
                                    <span class="po-number">{{ $order->order_number }}</span>
                                    <span class="po-date">{{ $order->order_date ? \Carbon\Carbon::parse($order->order_date)->format('M j') : '—' }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>

{{-- ───────────────────── ROW 4: Needs Attention (full width) ───────────────────── --}}
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">Needs Attention</h2>
            </div>
            <div class="box-body">
                <div class="action-grid">

                    <a class="action-card action-warranty" href="{{ route('hardware.index') }}">
                        <div class="action-title">Warranties expiring</div>
                        <div class="action-buckets">
                            <span><strong>{{ $actionQueue['warranty_30'] }}</strong> ≤ 30d</span>
                            <span><strong>{{ $actionQueue['warranty_60'] }}</strong> ≤ 60d</span>
                            <span><strong>{{ $actionQueue['warranty_90'] }}</strong> ≤ 90d</span>
                        </div>
                    </a>

                    @if ($actionQueue['lease_30'] !== null)
                    <a class="action-card action-lease" href="{{ route('hardware.index') }}">
                        <div class="action-title">Leases ending</div>
                        <div class="action-buckets">
                            <span><strong>{{ $actionQueue['lease_30'] }}</strong> ≤ 30d</span>
                            <span><strong>{{ $actionQueue['lease_60'] }}</strong> ≤ 60d</span>
                            <span><strong>{{ $actionQueue['lease_90'] }}</strong> ≤ 90d</span>
                        </div>
                    </a>
                    @endif

                    <a class="action-card action-audit" href="{{ route('assets.audit.due') }}">
                        <div class="action-title">Audits</div>
                        <div class="action-buckets">
                            <span class="action-bad"><strong>{{ $actionQueue['audit_overdue'] }}</strong> overdue</span>
                            <span><strong>{{ $actionQueue['audit_due_30'] }}</strong> due 30d</span>
                        </div>
                    </a>

                    <a class="action-card action-checkin" href="{{ route('assets.checkins.due') }}">
                        <div class="action-title">Checkins</div>
                        <div class="action-buckets">
                            <span><strong>{{ $actionQueue['checkin_due'] }}</strong> due</span>
                        </div>
                    </a>

                    <a class="action-card action-processing" href="{{ route('hardware.index') }}">
                        <div class="action-title">Stuck in Processing</div>
                        <div class="action-buckets">
                            <span class="action-bad"><strong>{{ $actionQueue['stuck_processing'] }}</strong> &gt; 14 days</span>
                        </div>
                    </a>

                    <a class="action-card action-maint" href="{{ url('maintenances') }}">
                        <div class="action-title">Maintenances</div>
                        <div class="action-buckets">
                            <span><strong>{{ $actionQueue['in_progress_maint'] }}</strong> in-progress</span>
                            <span><strong>{{ $actionQueue['scheduled_maint'] }}</strong> scheduled</span>
                            <span><strong>{{ $actionQueue['open_maint'] }}</strong> open</span>
                        </div>
                    </a>

                </div>
            </div>
        </div>
    </div>

    {{-- Procurement column moved up into Row 3 (alongside Recent Activity). --}}
</div>

{{-- ───────────────────── ROW 5: Breakdowns ───────────────────── --}}
<div class="row">
    <div class="col-md-6">
        {{-- Categories --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('general.asset') }} {{ trans('general.categories') }}</h2>
            </div>
            <div class="box-body">
                <table
                    data-cookie-id-table="dashCategorySummary"
                    data-height="320"
                    data-pagination="false"
                    data-side-pagination="server"
                    data-show-columns="false"
                    data-fixed-number="false"
                    data-fixed-right-number="false"
                    data-sort-order="desc"
                    data-sort-field="assets_count"
                    id="dashCategorySummary"
                    class="table table-striped snipe-table"
                    data-url="{{ route('api.categories.index', ['sort' => 'assets_count', 'order' => 'asc']) }}">
                    <thead>
                    <tr>
                        <th class="col-sm-4" data-visible="true" data-field="name" data-formatter="categoriesLinkFormatter" data-sortable="true">{{ trans('general.name') }}</th>
                        <th class="col-sm-2" data-visible="true" data-field="category_type" data-sortable="true">{{ trans('general.type') }}</th>
                        <th class="col-sm-1" data-visible="true" data-field="assets_count" data-sortable="true">
                            <x-icon type="assets" /><span class="sr-only">{{ trans('general.asset_count') }}</span>
                        </th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>

        @if (!empty($customBreakdowns['chip']))
            @include('partials.dashboard-breakdown', ['data' => $customBreakdowns['chip']])
        @endif
        @if (!empty($customBreakdowns['ownership_type']))
            @include('partials.dashboard-breakdown', ['data' => $customBreakdowns['ownership_type']])
        @endif
    </div>

    <div class="col-md-6">
        {{-- Locations --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('general.locations') }}</h2>
            </div>
            <div class="box-body">
                <table
                    data-cookie-id-table="dashLocationSummary"
                    data-height="320"
                    data-side-pagination="server"
                    data-pagination="false"
                    data-sort-order="desc"
                    data-fixed-number="false"
                    data-fixed-right-number="false"
                    data-sort-field="assets_count"
                    id="dashLocationSummary"
                    data-show-columns="false"
                    class="table table-striped snipe-table"
                    data-url="{{ route('api.locations.index', ['sort' => 'assets_count', 'order' => 'asc']) }}">
                    <thead>
                    <tr>
                        <th class="col-sm-4" data-visible="true" data-field="name" data-formatter="locationsLinkFormatter" data-sortable="true">{{ trans('general.name') }}</th>
                        <th class="col-sm-1" data-visible="true" data-field="assets_count" data-sortable="true">
                            <x-icon type="assets" /><span class="sr-only">{{ trans('general.asset_count') }}</span>
                        </th>
                        <th class="col-sm-1" data-visible="true" data-field="assigned_assets_count" data-sortable="true">{{ trans('general.assigned') }}</th>
                        <th class="col-sm-1" data-visible="true" data-field="users_count" data-sortable="true">
                            <x-icon type="users" /><span class="sr-only">{{ trans('general.people') }}</span>
                        </th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>

        @if (!empty($customBreakdowns['memory']))
            @include('partials.dashboard-breakdown', ['data' => $customBreakdowns['memory']])
        @endif
        @if (!empty($customBreakdowns['fleet']))
            @include('partials.dashboard-breakdown', ['data' => $customBreakdowns['fleet']])
        @endif
        @if (!empty($customBreakdowns['mdm']))
            @include('partials.dashboard-breakdown', ['data' => $customBreakdowns['mdm']])
        @endif
    </div>
</div>

@endif

@stop

@section('moar_scripts')
@include ('partials.bootstrap-table', ['simple_view' => true, 'nopages' => true])
@stop

@push('css')
<style>
    .dashboard-kpi-strip { margin-bottom: 15px; }
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(8, minmax(0, 1fr));
        gap: 8px;
    }
    @media (max-width: 1199px) { .kpi-grid { grid-template-columns: repeat(4, 1fr); } }
    @media (max-width: 767px)  { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

    .kpi-tile {
        display: block;
        padding: 12px 14px;
        border-radius: 4px;
        color: #fff;
        text-decoration: none;
        transition: transform 0.1s ease;
    }
    .kpi-tile:hover { transform: translateY(-2px); color: #fff; text-decoration: none; }
    /* Solid white text with a tiny shadow keeps the headline number legible on
       every card background (teal, green, yellow, etc.) without re-tuning each
       one separately. The label and subtext keep their previous hierarchy but
       at higher opacity so the meaning of each tile is readable too. */
    .kpi-tile .kpi-value { font-size: 24px; font-weight: 800; line-height: 1.1; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.18); }
    .kpi-tile .kpi-label { font-size: 12px; text-transform: uppercase; font-weight: 600; opacity: 1; margin-top: 2px; letter-spacing: 0.4px; color: #fff; }
    .kpi-tile .kpi-sub   { font-size: 11px; opacity: 0.92; margin-top: 4px; color: #fff; }

    .kpi-teal   { background: #39cccc; }
    .kpi-green  { background: #00a65a; }
    .kpi-blue   { background: #0073b7; }
    .kpi-aqua   { background: #00c0ef; }
    .kpi-yellow { background: #f39c12; }
    .kpi-red    { background: #dd4b39; }
    .kpi-purple { background: #605ca8; }
    .kpi-orange { background: #ff851b; }

    .lifecycle-bar {
        display: flex;
        height: 32px;
        border-radius: 4px;
        overflow: hidden;
        background: rgba(0,0,0,0.1);
    }
    .lifecycle-segment {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 12px;
        font-weight: 600;
        cursor: help;
        min-width: 2px;
    }
    .lifecycle-segment-label { white-space: nowrap; padding: 0 6px; }
    .lifecycle-legend {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px 24px;
        margin-top: 14px;
    }
    .lifecycle-stage-header {
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: 600;
        padding-bottom: 4px;
        border-bottom: 1px solid rgba(127,127,127,0.2);
        margin-bottom: 4px;
    }
    .lifecycle-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 2px; }
    .lifecycle-stage-name { flex: 1; }
    .lifecycle-stage-count { opacity: 0.6; font-weight: 500; }
    .lifecycle-items { list-style: none; padding: 0; margin: 0; }
    .lifecycle-items li a {
        display: flex; justify-content: space-between;
        padding: 2px 0; font-size: 13px;
    }
    .lifecycle-items .badge { background: rgba(127,127,127,0.2); color: inherit; }

    .action-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 10px;
    }
    .action-card {
        display: block;
        padding: 12px 14px;
        border-radius: 4px;
        border: 1px solid rgba(127,127,127,0.2);
        background: rgba(127,127,127,0.05);
        color: inherit;
        text-decoration: none;
        transition: background 0.1s ease;
    }
    .action-card:hover { background: rgba(127,127,127,0.12); text-decoration: none; color: inherit; }
    .action-title { font-weight: 600; margin-bottom: 6px; }
    .action-buckets { display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px; opacity: 0.85; }
    .action-buckets strong { font-size: 16px; opacity: 1; }
    .action-bad strong { color: #dd4b39; }
    .action-warranty   { border-left: 3px solid #f39c12; }
    .action-lease      { border-left: 3px solid #00c0ef; }
    .action-audit      { border-left: 3px solid #605ca8; }
    .action-checkin    { border-left: 3px solid #ff851b; }
    .action-processing { border-left: 3px solid #dd4b39; }
    .action-maint      { border-left: 3px solid #00a65a; }

    .procurement-mini-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 6px;
        margin-bottom: 10px;
    }
    .procurement-stat {
        display: block;
        text-align: center;
        padding: 8px 4px;
        border-radius: 4px;
        background: rgba(127,127,127,0.08);
        color: inherit;
        text-decoration: none;
    }
    .procurement-stat:hover { background: rgba(127,127,127,0.15); color: inherit; text-decoration: none; }
    .procurement-stat-value { display: block; font-size: 20px; font-weight: 700; }
    .procurement-stat-label { display: block; font-size: 11px; opacity: 0.7; }
    .procurement-warn { color: #dd4b39; }
    .procurement-section-title { font-size: 13px; text-transform: uppercase; opacity: 0.6; margin: 12px 0 4px; letter-spacing: 0.4px; }
    .procurement-list { list-style: none; padding: 0; margin: 0; }
    .procurement-list li a {
        display: flex; justify-content: space-between;
        padding: 4px 0; font-size: 13px;
        border-bottom: 1px solid rgba(127,127,127,0.1);
    }
    .procurement-list .po-supplier, .procurement-list .po-date { opacity: 0.65; font-size: 12px; }
    .procurement-empty { font-size: 12px; opacity: 0.6; font-style: italic; }

    .breakdown-bar {
        display: flex; align-items: center;
        gap: 8px; padding: 3px 0;
    }
    .breakdown-bar-name { width: 110px; font-size: 13px; flex-shrink: 0; }
    /* Track is intentionally light so the filled segment carries the visual weight.
       In dark mode the previous rgba(127,127,127,0.1) was invisible; bumping the
       alpha to .28 keeps the track readable on both themes without going opaque. */
    .breakdown-bar-track { flex: 1; height: 14px; background: rgba(127,127,127,0.28); border-radius: 3px; overflow: hidden; }
    .breakdown-bar-fill  { height: 100%; background: #4ba0d6; }
    .breakdown-bar-count { width: 50px; text-align: right; font-size: 12px; font-variant-numeric: tabular-nums; }
</style>
@endpush
