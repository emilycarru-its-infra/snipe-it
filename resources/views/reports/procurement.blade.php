@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/purchase-orders/general.dashboard_title') }}
    @parent
@stop

{{-- Page content --}}
@section('content')

<div class="row">
    <div class="col-md-12">
        <div class="box-header" style="padding-left:0;">
            <h1 class="box-title" style="font-size:22px; margin:0; display:inline-block; vertical-align:middle;">
                {{ trans('admin/purchase-orders/general.dashboard_title') }}
            </h1>
            <form method="get" style="display:inline-block; margin-left:15px; vertical-align:middle;">
                <select name="fiscal_year" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
                    <option value="all" {{ $selectedFy === null ? 'selected' : '' }}>{{ trans('admin/purchase-orders/general.all_fiscal_years') }}</option>
                    @foreach ($allFiscalYears as $fy)
                        <option value="{{ $fy }}" {{ $selectedFy === $fy ? 'selected' : '' }}>{{ $fy }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>
</div>

<div class="row proc-card-row">
    <div class="col-md-4 col-sm-6">
        @can('budget_allocations.manage')
            <a href="#" data-toggle="modal" data-target="#budgetAllocationsModal" class="small-box-link" style="text-decoration:none;">
        @endcan
            <div class="small-box bg-aqua">
                <div class="inner">
                    <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($totalBudget) }}</h3>
                    <p>
                        {{ trans('admin/purchase-orders/general.card_budget') }}
                        @if (! $budgetFromAllocations && count($poRows))
                            &middot; {{ trans('admin/purchase-orders/general.card_budget_from_pos') }}
                        @elseif ($budgetFromAllocations)
                            @can('budget_allocations.manage')
                                &middot; {{ $allocations->count() }} {{ trans('admin/budget-allocations/general.allocations') }}
                            @endcan
                        @endif
                        @if ($liveCarry)
                            &middot; {{ trans('admin/purchase-orders/general.card_budget_incl_carry', [
                                'amount' => '$'.Helper::formatCurrencyOutput($liveCarry['unused']),
                                'source' => $liveCarry['source_fy'],
                            ]) }}
                        @endif
                    </p>
                </div>
                <div class="icon"><i class="fas fa-wallet" aria-hidden="true"></i></div>
            </div>
        @can('budget_allocations.manage')
            </a>
        @endcan
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-yellow">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($totalCommitted) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_committed') }}</p>
            </div>
            <div class="icon"><i class="fas fa-file-signature" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box {{ $totalRemaining < 0 ? 'bg-red' : 'bg-green' }}">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($totalRemaining) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_remaining') }}</p>
            </div>
            <div class="icon"><i class="fas fa-balance-scale" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-blue">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($totalInvoiced) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_invoiced') }} &middot; {{ $invoiceCount }} {{ trans('admin/orders/general.invoices') }}</p>
            </div>
            <div class="icon"><i class="fas fa-receipt" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-navy">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($plannedTotal) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_forecast') }}</p>
            </div>
            <div class="icon"><i class="fas fa-chart-line" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-purple">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($eolEstimate) }}</h3>
                <p>{{ trans('admin/purchase-orders/general.card_eol', ['count' => $eolCount]) }}</p>
            </div>
            <div class="icon"><i class="fas fa-hourglass-end" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="small-box bg-teal">
            <div class="inner">
                <h3 style="font-size:24px">${{ Helper::formatCurrencyOutput($leaseExpiryTotal) }}</h3>
                <p>{!! trans(
                    $selectedFy
                        ? 'admin/purchase-orders/general.card_lease_preapproval'
                        : 'admin/purchase-orders/general.card_lease_preapproval_all',
                    ['count' => $leaseExpiryCount]
                ) !!}</p>
            </div>
            <div class="icon"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.procurement.invoice-approval') }}" class="small-box-link">
            <div class="small-box {{ $pendingApprovalCount > 0 ? 'bg-red' : 'bg-green' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ $pendingApprovalCount }}</h3>
                    <p>{{ trans('admin/purchase-orders/general.card_pending_approvals', ['count' => $pendingApprovalCount]) }}</p>
                </div>
                <div class="icon"><i class="fas fa-clipboard-check" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.procurement.lease-decisions', ['status' => 'pending']) }}" class="small-box-link">
            <div class="small-box {{ $pendingDecisionCount > 0 ? 'bg-yellow' : 'bg-aqua' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ $pendingDecisionCount }}</h3>
                    <p>{{ trans('admin/purchase-orders/general.card_pending_decisions', ['count' => $pendingDecisionCount]) }}</p>
                </div>
                <div class="icon"><i class="fas fa-handshake" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.procurement.user-agreement-ledger') }}" class="small-box-link">
            <div class="small-box {{ $userAgreementsAwaitingSignatureCount > 0 ? 'bg-yellow' : 'bg-aqua' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ $userAgreementsAwaitingSignatureCount }}</h3>
                    <p>{{ trans('admin/purchase-orders/general.card_user_agreements_unsigned', ['count' => $userAgreementsAwaitingSignatureCount]) }}</p>
                </div>
                <div class="icon"><i class="fas fa-file-signature" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-sm-6">
        <a href="{{ route('reports.procurement.schedule-signing') }}" class="small-box-link">
            <div class="small-box {{ $scheduleSigningQueueCount > 0 ? 'bg-yellow' : 'bg-aqua' }}">
                <div class="inner">
                    <h3 style="font-size:24px">{{ $scheduleSigningQueueCount }}</h3>
                    <p>{{ trans('admin/purchase-orders/general.card_schedules_unsigned', ['count' => $scheduleSigningQueueCount]) }}</p>
                </div>
                <div class="icon"><i class="fas fa-stamp" aria-hidden="true"></i></div>
            </div>
        </a>
    </div>
</div>

@if (count($leaseEndSchedules))
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ $selectedFy
                        ? trans('admin/purchase-orders/general.lease_end_title', ['fy' => $selectedFy])
                        : trans('admin/purchase-orders/general.lease_end_title_all') }}
                </h3>
                @can('create', \App\Models\Order::class)
                    <a href="{{ route('lease-decisions.create') }}" class="btn btn-default btn-xs pull-right">
                        {{ trans('admin/lease-decisions/general.create') }}
                    </a>
                @endcan
            </div>
            <div class="box-body">
                <p class="text-muted" style="margin-bottom:10px;">{{ trans('admin/purchase-orders/general.lease_end_help') }}</p>
                <style>
                    /* Keep contract / provider / end-date on one line each; let the
                       Models column be the flexible one that wraps and grows. The
                       Plan column has a reserved min-width so opening the inline
                       note editor doesn't reflow the whole table. */
                    .lease-end-table th:nth-child(1), .lease-end-table td:nth-child(1),
                    .lease-end-table th:nth-child(2), .lease-end-table td:nth-child(2),
                    .lease-end-table th:nth-child(3), .lease-end-table td:nth-child(3),
                    .lease-end-table th:nth-child(4), .lease-end-table td:nth-child(4) { white-space: nowrap; }
                    .lease-end-table td:nth-child(6) { white-space: normal; min-width: 280px; }
                    .lease-end-table th:nth-child(8), .lease-end-table td:nth-child(8) { min-width: 260px; }
                    .lease-end-table .rpt-note-input { width: 100%; box-sizing: border-box; }
                </style>
                <div class="table-responsive">
                    <table class="table table-striped lease-end-table" style="margin-bottom:0;">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/lease-decisions/general.contract_reference') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_provider') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_end_ownership') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_end_date') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.lease_end_devices') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_end_models') }}</th>
                                <th class="text-right">{{ trans('admin/purchase-orders/general.lease_end_replacement') }}</th>
                                <th>{{ trans('admin/purchase-orders/general.lease_end_plan') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($leaseEndSchedules as $schedule)
                                <tr>
                                    <td><strong>{{ $schedule['contract_id'] }}</strong></td>
                                    <td>{{ $schedule['provider'] }}</td>
                                    <td>
                                        @php $ownershipMix = $schedule['ownership_counts']; @endphp
                                        @if (empty($ownershipMix))
                                            <span class="text-muted">—</span>
                                        @elseif (count($ownershipMix) === 1)
                                            {{ array_key_first($ownershipMix) }}
                                        @else
                                            {{ collect($ownershipMix)->map(fn ($qty, $type) => $qty.'× '.$type)->implode(', ') }}
                                        @endif
                                    </td>
                                    <td>{{ $schedule['lease_end_date'] }}</td>
                                    <td class="text-right">{{ $schedule['count'] }}</td>
                                    <td>
                                        {{ collect($schedule['model_counts'])
                                            ->map(fn ($qty, $model) => $qty.'× '.$model)
                                            ->implode(', ') }}
                                    </td>
                                    <td class="text-right">${{ Helper::formatCurrencyOutput($schedule['cost']) }}</td>
                                    <td>
                                        @if ($schedule['is_lease_to_own'])
                                            <span class="label label-default">{{ trans('admin/purchase-orders/general.lease_end_retained') }}</span>
                                            <span class="text-muted" style="display:block; font-size:12px;">
                                                {{ trans('admin/purchase-orders/general.lease_end_retained_help') }}
                                            </span>
                                        @elseif ($schedule['decision'])
                                            <span class="label {{ $schedule['refresh_planned'] ? 'label-primary' : 'label-warning' }}">
                                                {{ trans('admin/lease-decisions/general.type_'.$schedule['decision']->decision_type) }}
                                                &middot;
                                                {{ trans('admin/lease-decisions/general.status_'.$schedule['decision']->status) }}
                                            </span>
                                            @unless ($schedule['refresh_planned'])
                                                <span class="text-muted" style="display:block; font-size:12px;">
                                                    {{ trans('admin/purchase-orders/general.lease_end_reassess') }}
                                                </span>
                                            @endunless
                                            <span class="rpt-note-cell" data-model="lease_decision" data-id="{{ $schedule['decision']->id }}" style="display:block; font-size:12px;">
                                                <span class="rpt-note-text text-muted">{{ $schedule['decision']->notes }}</span>
                                                @can('create', \App\Models\Order::class)
                                                    <a href="#" class="rpt-note-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_note') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                                @endcan
                                            </span>
                                        @else
                                            <span class="label label-success">{{ trans('admin/purchase-orders/general.lease_end_refresh_planned') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            @php
                                $leaseEndAll = collect($leaseEndSchedules);
                            @endphp
                            <tr>
                                <th colspan="4">{{ trans('admin/purchase-orders/general.lease_end_totals_preapproved') }}</th>
                                <th class="text-right">{{ $leaseEndAll->sum('count') }}</th>
                                <th></th>
                                <th class="text-right">${{ Helper::formatCurrencyOutput($leaseEndAll->sum('cost')) }}</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<div class="row">
    <div class="col-md-7">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_po') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:300px;">
                    <canvas id="procPoChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_utilization') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:300px;">
                    <canvas id="procUtilChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_fiscal_year') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:280px;">
                    <canvas id="procFyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/purchase-orders/general.chart_monthly') }}</h3>
            </div>
            <div class="box-body">
                <div style="position:relative; height:280px;">
                    <canvas id="procMonthlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

@php
    $hiddenReports = (array) (auth()->user()?->hidden_procurement_reports ?? []);

    // Fiscal-year scope is global and sticky (session-backed), so it's
    // safe to carry the current selection onto every report link;
    // reports that read fiscal_year with other semantics ignore it.
    $fyParam = $selectedFy ?? 'all';
    $reportLink = fn ($route, $extra = []) => route($route, array_merge(['fiscal_year' => $fyParam], $extra));

    // One list drives both the sticky jump-nav and the inline tables.
    $procReports = collect([
        ['route' => 'reports.procurement.po-budget', 'name' => 'report_po_budget', 'desc' => 'report_po_budget_desc'],
        ['route' => 'reports.procurement.invoices', 'name' => 'report_invoices', 'desc' => 'report_invoices_desc'],
        ['route' => 'reports.procurement.capital', 'name' => 'report_capital', 'desc' => 'report_capital_desc'],
        ['route' => 'reports.procurement.forecast', 'name' => 'report_forecast', 'desc' => 'report_forecast_desc'],
        ['route' => 'reports.procurement.user-agreement-ledger', 'name' => 'report_user_agreement_ledger', 'desc' => 'report_user_agreement_ledger_desc'],
        ['route' => 'reports.procurement.leases-operational', 'name' => 'report_leases_operational', 'desc' => 'report_leases_operational_desc'],
        ['route' => 'reports.procurement.leases-financial', 'name' => 'report_leases_financial', 'desc' => 'report_leases_financial_desc'],
        ['route' => 'reports.procurement.csi-schedule', 'name' => 'report_csi_schedule', 'desc' => 'report_csi_schedule_desc'],
        ['route' => 'reports.procurement.csi-reconciliation', 'name' => 'report_csi_reconciliation', 'desc' => 'report_csi_reconciliation_desc'],
        ['route' => 'reports.procurement.csi-arrivals', 'name' => 'report_csi_arrivals', 'desc' => 'report_csi_arrivals_desc'],
        ['route' => 'reports.procurement.invoice-approval', 'name' => 'report_invoice_approval', 'desc' => 'report_invoice_approval_desc'],
        ['route' => 'reports.procurement.lease-decisions', 'name' => 'report_lease_decisions', 'desc' => 'report_lease_decisions_desc'],
        ['route' => 'reports.procurement.po-disposition', 'name' => 'report_po_disposition', 'desc' => 'report_po_disposition_desc'],
        ['route' => 'reports.procurement.extension-watch', 'name' => 'report_extension_watch', 'desc' => 'report_extension_watch_desc'],
        ['route' => 'reports.procurement.aro-register', 'name' => 'report_aro_register', 'desc' => 'report_aro_register_desc'],
        ['route' => 'reports.procurement.asset-lease-detail', 'name' => 'report_asset_lease_detail', 'desc' => 'report_asset_lease_detail_desc'],
        ['route' => 'reports.procurement.po-drilldown', 'name' => 'report_po_drilldown', 'desc' => 'report_po_drilldown_desc'],
        ['route' => 'reports.procurement.disposition-grid', 'name' => 'report_disposition_grid', 'desc' => 'report_disposition_grid_desc'],
        ['route' => 'reports.procurement.credit-ledger', 'name' => 'report_credit_ledger', 'desc' => 'report_credit_ledger_desc'],
        ['route' => 'reports.procurement.lessor-breakdown', 'name' => 'report_lessor_breakdown', 'desc' => 'report_lessor_breakdown_desc'],
        ['route' => 'reports.procurement.pst-applicability', 'name' => 'report_pst_applicability', 'desc' => 'report_pst_applicability_desc'],
        ['route' => 'reports.procurement.schedule-signing', 'name' => 'report_schedule_signing', 'desc' => 'report_schedule_signing_desc'],
    ])->reject(fn ($r) => in_array($r['name'], $hiddenReports, true));
@endphp

<div class="row proc-reports-row">
    {{-- Sticky jump-nav so every report stays one click away in the long scroll. --}}
    <div class="proc-nav-col hidden-sm hidden-xs">
        <div class="proc-report-nav">
            <div class="box box-default" style="margin-bottom:0;">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase-orders/general.reports') }}</h3>
                </div>
                <div class="box-body no-padding">
                    <ul class="nav nav-pills nav-stacked proc-report-navlist">
                        @foreach ($procReports as $report)
                            <li>
                                <a href="#proc-{{ $report['name'] }}" data-target-report="proc-{{ $report['name'] }}">
                                    {{ trans('admin/purchase-orders/general.'.$report['name']) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if (! empty($hiddenReports))
                        <p style="padding:8px 12px; margin:0;">
                            <a href="#" id="show-all-procurement-reports">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                {{ trans('admin/purchase-orders/general.reports_hidden_count', ['count' => count($hiddenReports)]) }}
                            </a>
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="proc-content-col col-sm-12">
        @foreach ($procReports as $report)
            <div class="box box-default proc-report-box" id="proc-{{ $report['name'] }}" style="scroll-margin-top:64px;">
                <div class="box-header with-border">
                    <h3 class="box-title">
                        <a href="{{ $reportLink($report['route']) }}">{{ trans('admin/purchase-orders/general.'.$report['name']) }}</a>
                    </h3>
                    <div class="box-tools pull-right">
                        <a href="{{ $reportLink($report['route']) }}" class="btn btn-box-tool" data-tooltip="true" title="{{ trans('general.view') }}">
                            <x-icon type="reports" />
                        </a>
                        <a href="{{ $reportLink($report['route'], ['format' => 'csv']) }}" class="btn btn-box-tool" data-tooltip="true" title="{{ trans('general.download') }}">
                            <x-icon type="download" />
                        </a>
                        <a href="#" class="btn btn-box-tool hide-procurement-report"
                           data-report="{{ $report['name'] }}"
                           data-tooltip="true" title="{{ trans('admin/purchase-orders/general.report_hide') }}">
                            <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>
                        </a>
                        <button type="button" class="btn btn-box-tool" data-widget="collapse" data-tooltip="true" title="{{ trans('general.collapse') }}">
                            <i class="fa fa-minus" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/purchase-orders/general.'.$report['desc']) }}</p>
                    <div class="proc-report-body" data-embed-url="{{ $reportLink($report['route'], ['embed' => 1]) }}">
                        <div class="text-center text-muted" style="padding:18px;">
                            <i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

<style>
    html { scroll-behavior: smooth; }
    /* AdminLTE wraps the page in `.wrapper { overflow: hidden }` (and
       `.content-wrapper`), and an overflow ancestor traps position:sticky —
       the sticky nav can't pin to the viewport, so it just scrolls away.
       Lift the clip on this page so sticky resolves against the body scroll. */
    .wrapper, .content-wrapper { overflow: visible !important; }
    /* Flex row so the nav column stretches to the full height of the tables
       column — that's what gives position:sticky room to stay pinned through
       the whole scroll instead of stopping at the short nav box. */
    .proc-reports-row { display: flex; flex-wrap: wrap; }
    /* Narrow jump-nav (~44% slimmer than the old col-md-3) so the report
       tables get the width back. The content column flexes to fill the rest. */
    .proc-reports-row .proc-nav-col { flex: 0 0 14%; max-width: 14%; padding-left: 15px; padding-right: 15px; }
    .proc-reports-row .proc-content-col { flex: 1 1 0%; max-width: 86%; padding-left: 15px; padding-right: 15px; }
    .proc-report-nav { position: sticky; top: 16px; max-height: calc(100vh - 32px); overflow-y: auto; }
    .proc-report-navlist > li > a { padding: 6px 10px; font-size: 12.5px; border-radius: 0; }
    .proc-report-navlist > li.active > a,
    .proc-report-navlist > li.active > a:hover { background-color: #3c8dbc; color: #fff; }
    @media (max-width: 991px) {
        .proc-reports-row { display: block; }
        .proc-reports-row .proc-content-col { max-width: 100%; }
    }
    /* Static dashboard tiles — every card is the same height regardless of how
       much text sits under the number, so wrapping text never reflows the grid.
       Flexbox equalises each wrapped line of cards; the min-height keeps single-
       and double-line cards identical. */
    .proc-card-row { display: flex; flex-wrap: wrap; }
    .proc-card-row > [class*="col-"] { display: flex; margin-bottom: 15px; }
    .proc-card-row .small-box-link { display: flex; width: 100%; }
    .proc-card-row .small-box { width: 100%; min-height: 104px; margin-bottom: 0; display: flex; flex-direction: column; justify-content: center; }
    .proc-card-row .small-box > .inner { width: 100%; }
</style>

@can('budget_allocations.manage')
@include('reports.partials.budget-allocations-modal', [
    'allocations'        => $allocations,
    'liveCarry'          => $liveCarry,
    'selectedFy'         => $selectedFy,
    'allFiscalYears'     => $allFiscalYears,
    'budgetSourceLabels' => $budgetSourceLabels,
])
@endcan
@stop

@section('moar_scripts')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
    (function () {
        if (typeof Chart === 'undefined') { return; }

        var data = {!! json_encode([
            'poLabels' => array_column($poRows, 'po_number'),
            'poBudget' => array_column($poRows, 'budget'),
            'poCommitted' => array_column($poRows, 'committed'),
            'committed' => $totalCommitted,
            'remaining' => max($totalRemaining, 0),
            'fyLabels' => $fiscalYears,
            'fyCommitted' => array_map(fn ($fy) => $committedByFy[$fy] ?? 0, $fiscalYears),
            'fyPlanned' => array_map(fn ($fy) => $plannedByFy[$fy] ?? 0, $fiscalYears),
            'fyLeaseEnding' => array_map(fn ($fy) => $leaseExpiryByFy[$fy]['cost'] ?? 0, $fiscalYears),
            'monthlyLabels' => $monthlyLabels,
            'monthlyValues' => $monthlyValues,
        ]) !!};

        var money = function (value) {
            return '$' + Number(value).toLocaleString('en-CA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        };
        var moneyAxis = function () { return { ticks: { beginAtZero: true, callback: money } }; };
        var barTooltip = { callbacks: { label: function (item, data) {
            return data.datasets[item.datasetIndex].label + ': ' + money(item.yLabel);
        } } };
        var pieTooltip = { callbacks: { label: function (item, data) {
            return data.labels[item.index] + ': ' + money(data.datasets[0].data[item.index]);
        } } };

        new Chart(document.getElementById('procPoChart'), {
            type: 'bar',
            data: {
                labels: data.poLabels,
                datasets: [
                    { label: @json(trans('admin/purchase-orders/general.card_budget')), backgroundColor: '#00c0ef', data: data.poBudget },
                    { label: @json(trans('admin/purchase-orders/general.card_committed')), backgroundColor: '#f39c12', data: data.poCommitted }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTooltip, scales: { yAxes: [moneyAxis()] } }
        });

        new Chart(document.getElementById('procUtilChart'), {
            type: 'doughnut',
            data: {
                labels: [@json(trans('admin/purchase-orders/general.card_committed')), @json(trans('admin/purchase-orders/general.card_remaining'))],
                datasets: [{ backgroundColor: ['#f39c12', '#00a65a'], data: [data.committed, data.remaining] }]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: pieTooltip }
        });

        new Chart(document.getElementById('procFyChart'), {
            type: 'bar',
            data: {
                labels: data.fyLabels,
                datasets: [
                    { label: @json(trans('admin/purchase-orders/general.card_committed')), backgroundColor: '#f39c12', data: data.fyCommitted },
                    { label: @json(trans('admin/purchase-orders/general.card_forecast')), backgroundColor: '#001f3f', data: data.fyPlanned },
                    { label: @json(trans('admin/purchase-orders/general.chart_lease_ending')), backgroundColor: '#39cccc', data: data.fyLeaseEnding }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTooltip, scales: { yAxes: [moneyAxis()] } }
        });

        new Chart(document.getElementById('procMonthlyChart'), {
            type: 'line',
            data: {
                labels: data.monthlyLabels,
                datasets: [{
                    label: @json(trans('admin/purchase-orders/general.card_invoiced')),
                    borderColor: '#3c8dbc',
                    backgroundColor: 'rgba(60,141,188,0.15)',
                    data: data.monthlyValues
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, tooltips: barTooltip, scales: { yAxes: [moneyAxis()] } }
        });
    })();

    // Per-user show/hide for the procurement reports list. Each click
    // PATCHes the user's full hidden list to the visibility endpoint and
    // reloads. Persists across sessions via users.hidden_procurement_reports.
    (function () {
        var url = @json(route('reports.procurement.visibility'));
        var csrf = @json(csrf_token());
        var hidden = @json($hiddenReports);

        function save(list) {
            return fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ hidden: list })
            }).then(function () { window.location.reload(); });
        }

        document.querySelectorAll('.hide-procurement-report').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                var name = el.dataset.report;
                if (hidden.indexOf(name) !== -1) return;
                save(hidden.concat([name]));
            });
        });

        var showAll = document.getElementById('show-all-procurement-reports');
        if (showAll) {
            showAll.addEventListener('click', function (e) {
                e.preventDefault();
                save([]);
            });
        }
    })();

    // Lazy-load each report's table inline. Loaded one at a time (rather than
    // ~20 parallel queries) so heavy reports don't stampede the server; each
    // section fills in as it arrives.
    (function () {
        var pending = Array.prototype.slice.call(
            document.querySelectorAll('.proc-report-body[data-embed-url]')
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
        document.querySelectorAll('.proc-report-navlist a[data-target-report]').forEach(function (a) {
            links[a.dataset.targetReport] = a.parentElement;
        });
        var boxes = document.querySelectorAll('.proc-report-box');
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
{{-- Delegated handlers so the lazy-loaded Per-Serial Disposition Grid stays
     editable once it is injected into its report box. --}}
@include('reports.procurement._disposition-grid-js')
{{-- And the inline-editable note cells in the other report tables. --}}
@include('reports.procurement._report-note-js')
@stop
