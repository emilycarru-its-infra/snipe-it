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

{{-- The page that replaces the "Devices Capital Request" workbook. The
     arithmetic runs one way: the ending schedules' full original value IS
     the pre-approved budget, all of it gets requested, and this page shows
     how it is being distributed — the refresh forecast first, the manually
     entered new asks after, and whatever is not yet allocated as the number
     still to plan. This page understands lease ends and requisitions;
     orders already placed are a different page's problem. --}}
@section('content')

<p class="text-muted" style="max-width:900px;">{{ trans('admin/purchase-orders/general.capital_request_intro') }}</p>

<div style="display:flex; align-items:center; flex-wrap:wrap; gap:6px; margin-bottom:15px;" class="hidden-print">
    <a href="{{ route('reports.procurement.lease-end-schedules', ['fiscal_year' => $fy]) }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.report_lease_end_schedules') }}
    </a>
    <a href="{{ route('deployments.forecast', ['fiscal_year' => $fy]) }}" class="btn btn-sm btn-default">
        {{ trans('admin/deployments/general.forecast') }}
    </a>
    <a href="{{ route('purchase-orders.builder', ['fiscal_year' => $fy]) }}" class="btn btn-sm btn-default">
        {{ trans('admin/purchase-orders/general.report_po_builder') }}
    </a>
    @can('create', \App\Models\Requisition::class)
        @if ($refresh->isNotEmpty() || $newAskLines->isNotEmpty())
            <form method="POST" action="{{ route('reports.procurement.capital-request.draft') }}" style="margin:0 0 0 auto;"
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

{{-- The money, one line of arithmetic: the envelope is the request; the
     tiles after it show how much of it the lines below account for. --}}
<div class="row">
    @foreach ([
        ['label' => trans('admin/purchase-orders/general.capital_tile_envelope'), 'value' => '$'.number_format($envelope, 2), 'hint' => trans('admin/purchase-orders/general.capital_tile_envelope_hint', ['count' => $endingSchedules->count()])],
        ['label' => trans('admin/purchase-orders/general.capital_tile_refresh'), 'value' => '$'.number_format($refreshTotal, 2), 'hint' => trans('admin/purchase-orders/general.capital_tile_refresh_hint', ['count' => $refreshDevices])],
        ['label' => trans('admin/purchase-orders/general.capital_tile_new'), 'value' => '$'.number_format($newAskTotal, 2), 'hint' => trans('admin/purchase-orders/general.capital_tile_new_hint', ['count' => $newAskLines->count()])],
        ['label' => trans('admin/purchase-orders/general.capital_tile_remaining'), 'value' => ($remaining < 0 ? '-$' : '$').number_format(abs($remaining), 2), 'hint' => trans($remaining < 0 ? 'admin/purchase-orders/general.capital_tile_over_hint' : 'admin/purchase-orders/general.capital_tile_remaining_hint')],
    ] as $tile)
        <div class="col-md-3 col-sm-6">
            <div class="box box-default">
                <div class="box-body text-center">
                    <div class="text-muted" style="font-size:12px; text-transform:uppercase; letter-spacing:.06em;">{{ $tile['label'] }}</div>
                    <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $tile['value'] }}</div>
                    <div class="text-muted" style="font-size:11.5px; margin-top:2px;">{{ $tile['hint'] }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Where the ask stands: requisitions still waiting on a PO, then the
     paper finance has already issued. --}}
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
@endif

{{-- The budget's source: every schedule ending in the year and its full
     original value — the same rows as Lease Schedules Ending, condensed. --}}
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

{{-- The refresh distribution: the most likely replacements, priced from
     the live catalog. Kept contracts have no lines here — their budget
     stays in the envelope for redistribution. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.capital_section_refresh') }} — {{ $fy }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-striped capital-table">
            <thead>
                <tr>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_area') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.lease_contract_id') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.lease_qty') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.forecast_model') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.capital_col_unit') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.capital_col_cost') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_preference') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($refresh as $row)
                <tr>
                    <td>{{ $row['area'] }}</td>
                    <td>
                        <a href="{{ route('reports.procurement.lease-detail', $row['contract_id']) }}" class="js-lightbox"
                           title="{{ $row['contract_name'] }}">{{ $row['contract_id'] }}</a>
                    </td>
                    <td class="text-right">{{ $row['qty'] }}</td>
                    <td style="white-space:normal;">{{ $row['model'] }}</td>
                    <td class="text-right">
                        ${{ number_format($row['unit'], 2) }}
                        @if ($row['estimated'])<span class="label label-default">{{ trans('admin/purchase-orders/general.price_estimate') }}</span>@endif
                    </td>
                    <td class="text-right">${{ number_format($row['cost'], 2) }}</td>
                    <td>{{ $row['preference'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted">{{ trans('general.no_results') }}</td></tr>
            @endforelse
            </tbody>
            @if ($refresh->isNotEmpty())
                <tfoot>
                    <tr>
                        <th colspan="2">{{ trans('admin/orders/general.total') }}</th>
                        <th class="text-right">{{ $refreshDevices }}</th>
                        <th></th>
                        <th></th>
                        <th class="text-right">${{ number_format($refreshTotal, 2) }}</th>
                        <th></th>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- The new asks: typed in, exactly like the workbook's "New Ask" rows.
     This is where the rest of the envelope gets distributed. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.capital_section_new_asks') }}</h3>
        @if ($remaining > 0)
            <div class="box-tools pull-right">
                <span class="label label-info">{{ trans('admin/purchase-orders/general.capital_remaining_chip', ['amount' => number_format($remaining, 2)]) }}</span>
            </div>
        @endif
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-striped capital-table">
            <thead>
                <tr>
                    <th>{{ trans('admin/purchase-orders/general.capital_col_need') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.lease_qty') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.forecast_model') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.capital_col_unit') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.capital_col_cost') }}</th>
                    <th style="width:50px;"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($newAskLines as $line)
                <tr>
                    <td>{{ $line->need }}</td>
                    <td class="text-right">{{ $line->quantity }}</td>
                    <td style="white-space:normal;">{{ $line->description }}</td>
                    <td class="text-right">${{ number_format((float) $line->unit_cost, 2) }}</td>
                    <td class="text-right">${{ number_format($line->lineTotal(), 2) }}</td>
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
            @empty
                <tr><td colspan="6" class="text-center text-muted">{{ trans('admin/purchase-orders/general.capital_new_asks_empty') }}</td></tr>
            @endforelse
            @can('create', \App\Models\Requisition::class)
                <tr>
                    <td>
                        <form id="capital-new-ask" method="POST" action="{{ route('reports.procurement.capital-request.lines.store') }}">
                            {{ csrf_field() }}
                            <input type="hidden" name="fiscal_year" value="{{ $fy }}">
                        </form>
                        <input form="capital-new-ask" type="text" name="need" class="form-control input-sm" required
                               placeholder="{{ trans('admin/purchase-orders/general.capital_need_placeholder') }}">
                    </td>
                    <td><input form="capital-new-ask" type="number" name="quantity" class="form-control input-sm text-right" min="1" value="1" required></td>
                    <td><input form="capital-new-ask" type="text" name="description" class="form-control input-sm" required
                               placeholder="{{ trans('admin/purchase-orders/general.capital_model_placeholder') }}"></td>
                    <td><input form="capital-new-ask" type="number" name="unit_cost" class="form-control input-sm text-right" min="0" step="0.01" required placeholder="0.00"></td>
                    <td></td>
                    <td class="text-right">
                        <button form="capital-new-ask" type="submit" class="btn btn-xs btn-primary"><i class="fas fa-plus"></i></button>
                    </td>
                </tr>
            @endcan
            </tbody>
            @if ($newAskLines->isNotEmpty())
                <tfoot>
                    <tr>
                        <th>{{ trans('admin/orders/general.total') }}</th>
                        <th class="text-right">{{ $newAskLines->sum('quantity') }}</th>
                        <th></th>
                        <th></th>
                        <th class="text-right">${{ number_format($newAskTotal, 2) }}</th>
                        <th></th>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

<style>
    .capital-table td, .capital-table th { white-space: nowrap; }
</style>

@stop
