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
                    <a href="{{ route('purchase-orders.builder', ['requisition' => $requisition->id]) }}">
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

{{-- The request itself — one table, refresh and new asks together,
     exactly as the workbook read. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.capital_request_title') }} — {{ $fy }}</h3>
        <div class="box-tools pull-right" style="display:flex; align-items:center; gap:6px;">
            @if ($remaining > 0)
                <span class="label label-info">{{ trans('admin/purchase-orders/general.capital_remaining_chip', ['amount' => number_format($remaining, 2)]) }}</span>
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
                @if ($refresh->isNotEmpty() || $newAskLines->isNotEmpty())
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
            <tbody>
            @forelse ($refresh as $row)
                <tr>
                    <td>{{ trans('admin/purchase-orders/general.capital_need_refresh') }}</td>
                    <td>
                        <a href="{{ route('reports.procurement.lease-detail', $row['contract_id']) }}" class="js-lightbox"
                           title="{{ $row['contract_name'] }}">{{ $row['contract_id'] }}</a>
                    </td>
                    <td>{{ $row['area'] }}</td>
                    <td>{{ $row['preference'] }}</td>
                    <td>{{ $row['type'] ?: '—' }}</td>
                    <td class="text-right">{{ $row['qty'] }}</td>
                    <td style="white-space:normal;">{{ $row['model'] }}</td>
                    <td class="text-right">${{ number_format($row['cost'], 2) }}</td>
                    <td class="text-right">
                        ${{ number_format($row['unit'], 2) }}
                        @if ($row['estimated'])<span class="label label-default">{{ trans('admin/purchase-orders/general.price_estimate') }}</span>@endif
                    </td>
                    <td>
                        @forelse ($row['waves'] as $waveId => $waveName)
                            <a href="{{ route('deployment-waves.show', $waveId) }}">{{ $waveName }}</a>@if (! $loop->last), @endif
                        @empty
                            <span class="text-muted">&mdash;</span>
                        @endforelse
                    </td>
                    <td>
                        @if ($row['reqm'])
                            <a href="{{ route('purchase-orders.builder', ['requisition' => $row['requisition_id']]) }}">{{ $row['reqm'] }}</a>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td>
                        @if ($row['po'])
                            <a href="{{ route('purchase-orders.show', $row['po']) }}">{{ $row['po'] }}</a>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td></td>
                </tr>
            @empty
                @if ($newAskLines->isEmpty())
                    <tr><td colspan="13" class="text-center text-muted">{{ trans('general.no_results') }}</td></tr>
                @endif
            @endforelse
            @foreach ($newAskLines as $line)
                @php($paper = $newAskPaper[$line->id] ?? null)
                <tr>
                    <td>{{ $line->need }}</td>
                    <td></td>
                    <td>{{ $line->area ?: '—' }}</td>
                    <td>{{ $line->preference ?: '—' }}</td>
                    <td>{{ $line->type ?: '—' }}</td>
                    <td class="text-right">{{ $line->quantity }}</td>
                    <td style="white-space:normal;">{{ $line->description }}</td>
                    <td class="text-right">${{ number_format($line->lineTotal(), 2) }}</td>
                    <td class="text-right">${{ number_format((float) $line->unit_cost, 2) }}</td>
                    <td><span class="text-muted">&mdash;</span></td>
                    <td>
                        @if ($paper && $paper['reqm'])
                            <a href="{{ route('purchase-orders.builder', ['requisition' => $paper['requisition_id']]) }}">{{ $paper['reqm'] }}</a>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td>
                        @if ($paper && $paper['po'])
                            <a href="{{ route('purchase-orders.show', $paper['po']) }}">{{ $paper['po'] }}</a>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td class="text-right">
                        @can('create', \App\Models\Requisition::class)
                            <form method="POST" action="{{ route('reports.procurement.capital-request.lines.destroy', $line) }}" style="display:inline-block; margin:0;"
                                  onsubmit="return confirm({{ json_encode(trans('general.sure_to_delete_var', ['item' => $line->need])) }});">
                                {{ csrf_field() }}@method('DELETE')
                                <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
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

{{-- The budget's source, beneath the request it funds: every schedule
     ending in the year at its full original value — the pre-approved
     envelope the lines above distribute. --}}
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
                    <td style="white-space:normal;">
                        @if ($schedule['is_lease_to_own'])
                            {{ trans('admin/purchase-orders/general.lease_end_retained') }}
                        @elseif ($schedule['decision'])
                            {{ trans('admin/lease-decisions/general.type_'.$schedule['decision']->decision_type) }}
                        @else
                            {{ trans('admin/purchase-orders/general.lease_end_refresh_planned') }}
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
                    <a href="{{ route('purchase-orders.show', $po) }}">{{ $po->po_number }}</a>
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
