@extends('layouts/default')

@section('title')
    {{ trans('admin/purchase-orders/general.capital_request_title') }} — {{ $fy }}
    @parent
@stop

@section('header_right')
    <form method="get" style="display:inline-block; margin-right:4px;">
        <select name="fiscal_year" class="form-control input-sm" style="display:inline-block; width:auto;" onchange="this.form.submit()">
            @foreach ($allFiscalYears as $fyOption)
                <option value="{{ $fyOption }}" @selected($fyOption === $fy)>{{ $fyOption }}</option>
            @endforeach
            @unless ($allFiscalYears->contains($fy))
                <option value="{{ $fy }}" selected>{{ $fy }}</option>
            @endunless
        </select>
    </form>
    <a href="{{ route('reports.procurement.capital-request', ['format' => 'csv', 'fiscal_year' => $fy]) }}" class="btn btn-sm btn-default">
        <x-icon type="download" /> {{ trans('general.download') }}
    </a>
@stop

{{-- The workbook, as one page: one table of request lines — the lease-end
     refresh distribution and the typed-in new asks together — spending the
     pre-approved envelope (the ending schedules' full original value).
     The New Ask button opens a popover, the draft button turns the table
     into a builder basket, and the year's paper trail sits at the foot. --}}
@section('content')
    @include('reports.procurement._report-note-js')
    <style>
        /* Same Plan treatment as Lease Schedules Ending: the retained note
           is the plan, not a footnote. */
        .lease-end-retained-note {
            display: block;
            margin-top: 6px;
            padding: 6px 10px;
            font-size: 13px;
            color: var(--color-fg, #262626);
            background: light-dark(rgba(60, 141, 188, .08), rgba(60, 141, 188, .16));
            border-radius: 6px;
            max-width: 560px;
        }
        .capital-table .rpt-note-input { width: 100%; box-sizing: border-box; }
    </style>

<p class="text-muted" style="max-width:900px;">{{ trans('admin/purchase-orders/general.capital_request_intro') }}</p>

@if ($openRequisitions->isNotEmpty())
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.capital_reqs_title') }}</h3>
    </div>
    <div class="box-body">
        <ul class="list-unstyled" style="margin:0;">
            @foreach ($openRequisitions as $requisition)
                <li style="margin-bottom:4px;">
                    <a href="{{ route('requisitions.show', $requisition->id) }}">
                        {{ $requisition->requisition_number ? 'REQM '.$requisition->requisition_number : $requisition->title }}
                    </a>
                    @if ($requisition->requisition_number && $requisition->title)<span class="text-muted">· {{ $requisition->title }}</span>@endif
                    <span class="label label-default">{{ ucfirst($requisition->status) }}</span>
                    <span class="text-muted">· ${{ number_format($requisition->items->sum(fn ($line) => $line->lineTotal()), 2) }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</div>
@endif

{{-- The budget first: every schedule ending in the year at its full
     original value — the pre-approved envelope the request below
     distributes. Approvers read the money, then the ask against it. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.capital_envelope_title') }} — {{ $fy }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-striped capital-table">
            <thead>
                <tr>
                    <th>{{ trans('admin/purchase-orders/general.lease_contract_id') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.lease_end_ownership') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.lease_end_date') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.lease_end_devices') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.capital_envelope_value') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.lease_end_plan') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($endingSchedules as $schedule)
                <tr>
                    <td>
                        <a href="{{ route('reports.procurement.lease-detail', $schedule['contract_id']) }}" class="js-lightbox">{{ $schedule['contract_id'] }}</a>
                    </td>
                    <td>{{ collect($schedule['ownership_counts'])->keys()->implode(', ') ?: '—' }}</td>
                    <td>{{ $schedule['lease_end_date'] }}</td>
                    <td class="text-right">{{ $schedule['count'] }}</td>
                    <td class="text-right">${{ number_format($schedule['cost'], 2) }}</td>
                    {{-- Same Plan design as Lease Schedules Ending: the pill
                         carries the decision, the note block carries its
                         budget consequence, both editable in place. --}}
                    <td style="white-space:normal; min-width:260px;">
                        @if ($schedule['is_lease_to_own'])
                            <span class="label label-default">{{ trans('admin/purchase-orders/general.lease_end_retained') }}</span>
                            <span class="lease-end-retained-note rpt-note-cell" data-model="lease_plan_note" data-contract="{{ $schedule['contract_id'] }}">
                                <span class="rpt-note-text">{{ $schedule['plan_note'] !== '' ? $schedule['plan_note'] : trans('admin/purchase-orders/general.lease_end_retained_help') }}</span>
                                @can('create', \App\Models\Order::class)
                                    <a href="#" class="rpt-note-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_note') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                @endcan
                            </span>
                        @elseif ($schedule['decision'])
                            <span class="label {{ $schedule['refresh_planned'] ? 'label-primary' : 'label-warning' }}">
                                {{ trans('admin/purchase-orders/general.lease_end_decision_tag', [
                                    'type' => trans('admin/lease-decisions/general.type_'.$schedule['decision']->decision_type),
                                    'status' => trans('admin/purchase-orders/general.decision_status_'.$schedule['decision']->status),
                                ]) }}
                            </span>
                            <span class="rpt-note-cell" data-model="lease_decision" data-id="{{ $schedule['decision']->id }}" style="display:block; font-size:12px;">
                                <span class="rpt-note-text text-muted">{{ $schedule['decision']->notes }}</span>
                                @can('create', \App\Models\Order::class)
                                    <a href="#" class="rpt-note-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_note') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                @endcan
                            </span>
                        @else
                            <span class="label label-success">{{ trans('admin/purchase-orders/general.lease_end_refresh_planned') }}</span>
                            <span class="rpt-note-cell" data-model="lease_plan_note" data-contract="{{ $schedule['contract_id'] }}" style="display:block; font-size:12px;">
                                <span class="rpt-note-text text-muted">{{ $schedule['plan_note'] }}</span>
                                @can('create', \App\Models\Order::class)
                                    <a href="#" class="rpt-note-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_note') }}"><i class="fa-solid fa-pencil" aria-hidden="true"></i></a>
                                @endcan
                            </span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted">{{ trans('general.no_results') }}</td></tr>
            @endforelse
            </tbody>
            @if ($endingSchedules->isNotEmpty())
                <tfoot>
                    <tr>
                        <th colspan="3">{{ trans('admin/purchase-orders/general.lease_end_totals_preapproved') }}</th>
                        <th class="text-right">{{ $endingSchedules->sum('count') }}</th>
                        <th class="text-right">${{ number_format($envelope, 2) }}</th>
                        <th></th>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- The request itself — one table, refresh and new asks together,
     exactly as the workbook read. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.capital_request_title') }} — {{ $fy }}</h3>
        <div class="box-tools pull-right" style="display:flex; align-items:center; gap:6px;">
            @if ($remaining > 0)
                {{-- Sized to the buttons it sits between — the bare label's
                     own line-height made it a squashed pill in the flex row. --}}
                <span class="label label-info capital-remaining-chip">{{ trans('admin/purchase-orders/general.capital_remaining_chip', ['amount' => number_format($remaining, 2)]) }}</span>
            @endif
            @can('create', \App\Models\Requisition::class)
                <span class="nw-pop-wrap" style="position:relative; display:inline-block;">
                    <button type="button" class="btn btn-sm btn-default nw-pop-toggle" data-pop="capital-ask-pop">
                        <i class="fas fa-plus"></i> {{ trans('admin/purchase-orders/general.capital_new_ask_button') }}
                    </button>
                    <div class="nw-pop nw-pop-right" id="capital-ask-pop">
                        <form method="POST" action="{{ route('reports.procurement.capital-request.lines.store') }}">
                            @csrf
                            <input type="hidden" name="fiscal_year" value="{{ $fy }}">
                            <div class="form-group" style="margin-bottom:8px;">
                                <label style="margin-bottom:2px;">{{ trans('admin/purchase-orders/general.capital_col_need') }}</label>
                                <input type="text" name="need" class="form-control input-sm" required
                                       placeholder="{{ trans('admin/purchase-orders/general.capital_need_placeholder') }}">
                            </div>
                            <div class="form-group" style="margin-bottom:8px;">
                                <label style="margin-bottom:2px;">{{ trans('admin/purchase-orders/general.forecast_model') }}</label>
                                <input type="text" name="description" class="form-control input-sm" required
                                       placeholder="{{ trans('admin/purchase-orders/general.capital_model_placeholder') }}">
                            </div>
                            <div style="display:flex; gap:6px; margin-bottom:8px;">
                                <div style="flex:1;">
                                    <label style="margin-bottom:2px;">{{ trans('admin/purchase-orders/general.lease_qty') }}</label>
                                    <input type="number" name="quantity" class="form-control input-sm" min="1" value="1" required>
                                </div>
                                <div style="flex:2;">
                                    <label style="margin-bottom:2px;">{{ trans('admin/purchase-orders/general.capital_col_unit') }}</label>
                                    <input type="number" name="unit_cost" class="form-control input-sm" min="0" step="0.01" required placeholder="0.00">
                                </div>
                            </div>
                            <div style="display:flex; gap:6px; margin-bottom:8px;">
                                <div style="flex:1;">
                                    <label style="margin-bottom:2px;">{{ trans('admin/purchase-orders/general.capital_col_area') }}</label>
                                    <select name="area" class="form-control input-sm">
                                        <option value="">—</option>
                                        <option>Curriculum</option>
                                        <option>Admin</option>
                                    </select>
                                </div>
                                <div style="flex:1;">
                                    <label style="margin-bottom:2px;">{{ trans('admin/purchase-orders/general.capital_col_type') }}</label>
                                    <input type="text" name="type" class="form-control input-sm" placeholder="Laptop">
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom:10px;">
                                <label style="margin-bottom:2px;">{{ trans('admin/purchase-orders/general.capital_col_schedule') }}</label>
                                <select name="preference" class="form-control input-sm">
                                    <option value="">—</option>
                                    <option>{{ trans('admin/purchase-orders/general.capital_pref_rental') }}</option>
                                    <option>{{ trans('admin/purchase-orders/general.capital_pref_lto') }}</option>
                                </select>
                            </div>
                            <div class="text-right">
                                <button type="button" class="btn btn-sm btn-default nw-pop-cancel">{{ trans('button.cancel') }}</button>
                                <button type="submit" class="btn btn-sm btn-primary">{{ trans('admin/purchase-orders/general.capital_new_ask_button') }}</button>
                            </div>
                        </form>
                    </div>
                </span>
                @if ($requisitionBacked)
                    {{-- The request became paper; the button is the paper. --}}
                    @foreach ($capitalRequisitions as $capReq)
                        <a href="{{ route('requisitions.show', $capReq->id) }}" class="btn btn-sm btn-primary">
                            <i class="fas fa-file-invoice" aria-hidden="true"></i>
                            {{ $capReq->requisition_number ? 'REQM '.$capReq->requisition_number : trans('admin/purchase-orders/general.capital_view_requisition') }}
                        </a>
                    @endforeach
                @elseif ($refresh->isNotEmpty() || $newAskLines->isNotEmpty())
                    <form method="POST" action="{{ route('reports.procurement.capital-request.draft') }}" style="margin:0;"
                          onsubmit="return confirm({{ json_encode(trans('admin/purchase-orders/general.capital_draft_confirm', ['fy' => $fy])) }});">
                        {{ csrf_field() }}
                        <input type="hidden" name="fiscal_year" value="{{ $fy }}">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-cart-plus" aria-hidden="true"></i>
                            {{ trans('admin/purchase-orders/general.capital_draft_button') }}
                        </button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-striped capital-table">
            <thead>
                <tr>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_need') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_ending_contract') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_area') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_schedule') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_type') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.lease_qty') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.forecast_model') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.capital_col_cost') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.capital_col_unit') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_wave') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_reqm') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_po') }}</th>
                    <th style="width:40px;"></th>
                </tr>
            </thead>
            @php
                // Requisition-backed: the table IS the paper — every row
                // already names its REQM/PO, so grouping under a header
                // would only add a line that isn't on the PO. Flat, exact,
                // visibly a match.
                // Otherwise: lines already landed on a REQM cluster under
                // it, the way Extension Watch nests units under their
                // contract; lines still looking for paper stay flat at the
                // top.
                $reqmGroups = collect();
                $plainRefresh = $requisitionBacked ? collect($refresh) : collect();
                foreach ($requisitionBacked ? [] : $refresh as $capitalRow) {
                    if ($capitalRow['reqm']) {
                        $g = $reqmGroups->get($capitalRow['reqm'], [
                            'reqm' => $capitalRow['reqm'],
                            'requisition_id' => $capitalRow['requisition_id'],
                            'po' => $capitalRow['po'],
                            'refresh' => collect(), 'asks' => collect(),
                            'qty' => 0, 'cost' => 0.0,
                        ]);
                        $g['refresh']->push($capitalRow);
                        $g['qty'] += $capitalRow['qty'];
                        $g['cost'] += $capitalRow['cost'];
                        $reqmGroups->put($capitalRow['reqm'], $g);
                    } else {
                        $plainRefresh->push($capitalRow);
                    }
                }
                $plainAsks = $requisitionBacked ? collect($newAskLines) : collect();
                foreach ($requisitionBacked ? [] : $newAskLines as $askLine) {
                    $askPaper = $newAskPaper[$askLine->id] ?? null;
                    if ($askPaper && $askPaper['reqm']) {
                        $g = $reqmGroups->get($askPaper['reqm'], [
                            'reqm' => $askPaper['reqm'],
                            'requisition_id' => $askPaper['requisition_id'],
                            'po' => $askPaper['po'],
                            'refresh' => collect(), 'asks' => collect(),
                            'qty' => 0, 'cost' => 0.0,
                        ]);
                        $g['asks']->push($askLine);
                        $g['qty'] += $askLine->quantity;
                        $g['cost'] += $askLine->lineTotal();
                        $reqmGroups->put($askPaper['reqm'], $g);
                    } else {
                        $plainAsks->push($askLine);
                    }
                }
            @endphp
            <tbody>
            @if ($plainRefresh->isEmpty() && $plainAsks->isEmpty() && $reqmGroups->isEmpty())
                <tr><td colspan="13" class="text-center text-muted">{{ trans('general.no_results') }}</td></tr>
            @endif
            @foreach ($plainRefresh as $row)
                @include('reports.procurement._capital-refresh-row', ['row' => $row, 'inGroup' => false])
            @endforeach
            @foreach ($plainAsks as $line)
                @include('reports.procurement._capital-ask-row', ['line' => $line, 'paper' => null, 'inGroup' => false])
            @endforeach
            </tbody>
            @foreach ($reqmGroups as $group)
                <tbody class="capital-reqm-group">
                    <tr class="capital-group-head">
                        <td colspan="5">
                            <button type="button" class="btn btn-xs btn-default capital-group-toggle" aria-expanded="true">
                                <i class="fas fa-chevron-down" aria-hidden="true"></i>
                            </button>
                            <a href="{{ route('requisitions.show', $group['requisition_id']) }}"><strong>{{ $group['reqm'] }}</strong></a>
                            <span class="text-muted">· {{ $group['refresh']->count() + $group['asks']->count() }} {{ trans('admin/purchase-orders/general.capital_group_lines') }}</span>
                        </td>
                        <td class="text-right"><strong>{{ $group['qty'] }}</strong></td>
                        <td></td>
                        <td class="text-right"><strong>${{ number_format($group['cost'], 2) }}</strong></td>
                        <td></td>
                        <td></td>
                        <td><a href="{{ route('requisitions.show', $group['requisition_id']) }}">{{ $group['reqm'] }}</a></td>
                        <td>
                            @if ($group['po'])
                                <a class="js-lightbox" href="{{ route('purchase-orders.show', $group['po']) }}">{{ $group['po'] }}</a>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </td>
                        <td></td>
                    </tr>
                    @foreach ($group['refresh'] as $row)
                        @include('reports.procurement._capital-refresh-row', ['row' => $row, 'inGroup' => true])
                    @endforeach
                    @foreach ($group['asks'] as $line)
                        @include('reports.procurement._capital-ask-row', ['line' => $line, 'paper' => null, 'inGroup' => true])
                    @endforeach
                </tbody>
            @endforeach
            @if ($refresh->isNotEmpty() || $newAskLines->isNotEmpty())
                <tfoot>
                    <tr>
                        <th colspan="5">{{ trans('admin/orders/general.total') }}</th>
                        <th class="text-right">{{ $refreshDevices + $newAskLines->sum('quantity') }}</th>
                        <th></th>
                        <th class="text-right">${{ number_format($refreshTotal + $newAskTotal, 2) }}</th>
                        <th colspan="5"></th>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- The paper trail, at the foot. --}}
@if ($purchaseOrders->isNotEmpty())
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.capital_pos_title') }}</h3>
    </div>
    <div class="box-body">
        <ul class="list-unstyled" style="margin:0;">
            @foreach ($purchaseOrders as $po)
                <li style="margin-bottom:4px;">
                    <a class="js-lightbox" href="{{ route('purchase-orders.show', $po) }}">{{ $po->po_number }}</a>
                    @if ($po->title)<span class="text-muted">· {{ $po->title }}</span>@endif
                    @if ($po->budget)<span class="text-muted">· ${{ number_format((float) $po->budget, 2) }}</span>@endif
                </li>
            @endforeach
        </ul>
    </div>
</div>
@else
    <p class="text-muted">{{ trans('admin/purchase-orders/general.capital_pos_none') }}</p>
@endif

<style>
    .capital-table td, .capital-table th { white-space: nowrap; }
    .capital-group-head td {
        background: color-mix(in srgb, var(--color-fg, #333) 5%, var(--box-bg, #fff));
        border-top: 2px solid var(--box-header-bottom-border-color, #e4e9ee);
    }
    .capital-group-toggle { opacity: .7; margin-right: 6px; }
    .capital-group-toggle:hover { opacity: 1; }
    .capital-remaining-chip {
        display: inline-flex;
        align-items: center;
        height: 30px;
        padding: 0 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }
    /* The popover pattern from the New Wave button, anchored right so it
       stays on screen when opened from box-tools. */
    .nw-pop {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        z-index: 1040;
        width: 340px;
        padding: 14px;
        background: var(--box-bg, #fff);
        border: 1px solid var(--box-border-color, #e5e5e5);
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(0, 0, 0, .18);
        text-align: left;
    }
    .nw-pop.open { display: block; }
    .nw-pop-right { left: auto; right: 0; }
    .nw-pop::before {
        content: '';
        position: absolute;
        top: -7px;
        left: 24px;
        width: 12px;
        height: 12px;
        background: var(--box-bg, #fff);
        border-left: 1px solid var(--box-border-color, #e5e5e5);
        border-top: 1px solid var(--box-border-color, #e5e5e5);
        transform: rotate(45deg);
    }
    .nw-pop-right::before { left: auto; right: 24px; }
</style>
<script nonce="{{ csrf_token() }}">
document.querySelectorAll('.capital-group-toggle').forEach(function (toggle) {
    toggle.addEventListener('click', function () {
        var body = toggle.closest('tbody');
        var expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        toggle.querySelector('i').className = expanded ? 'fas fa-chevron-right' : 'fas fa-chevron-down';
        body.querySelectorAll('tr:not(.capital-group-head)').forEach(function (row) {
            row.style.display = expanded ? 'none' : '';
        });
    });
});
document.addEventListener('click', function (e) {
    var toggle = e.target.closest('.nw-pop-toggle');
    if (toggle) {
        var pop = document.getElementById(toggle.getAttribute('data-pop'));
        pop.classList.toggle('open');
        return;
    }
    if (e.target.closest('.nw-pop-cancel')) {
        e.target.closest('.nw-pop').classList.remove('open');
        return;
    }
    if (!e.target.closest('.nw-pop')) {
        document.querySelectorAll('.nw-pop.open').forEach(function (pop) { pop.classList.remove('open'); });
    }
});
</script>

@stop
