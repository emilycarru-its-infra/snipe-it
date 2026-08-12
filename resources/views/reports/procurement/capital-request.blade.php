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

{{-- The page that replaces the "Devices Capital Request" workbook: one
     link for the head of finance, readable without knowing the app. The
     March snapshot and the in-year actuals are the same page — estimates
     follow the catalog as models and prices move. --}}
@section('content')

<p class="text-muted" style="max-width:900px;">{{ trans('admin/purchase-orders/general.capital_request_intro') }}</p>

<div class="row">
    @foreach ([
        ['label' => trans('admin/purchase-orders/general.capital_tile_devices'), 'value' => number_format($refreshDevices)],
        ['label' => trans('admin/purchase-orders/general.capital_tile_refresh'), 'value' => '$'.number_format($refreshTotal, 2)],
        ['label' => trans('admin/purchase-orders/general.capital_tile_new'), 'value' => '$'.number_format($newAskTotal, 2)],
        ['label' => trans('admin/purchase-orders/general.capital_tile_grand'), 'value' => '$'.number_format($grandTotal, 2), 'strong' => true],
    ] as $tile)
        <div class="col-md-3 col-sm-6">
            <div class="box box-default">
                <div class="box-body text-center">
                    <div class="text-muted" style="font-size:12px; text-transform:uppercase; letter-spacing:.06em;">{{ $tile['label'] }}</div>
                    <div style="font-size:22px; font-weight:700; margin-top:4px;">{{ $tile['value'] }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

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

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.capital_section_new_asks') }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        @if (empty($newAsks))
            <p class="text-muted" style="padding:12px 14px; margin:0;">{{ trans('admin/purchase-orders/general.capital_new_asks_empty') }}</p>
        @else
            <table class="table table-striped capital-table">
                <thead>
                    <tr>
                        <th>{{ trans('admin/purchase-orders/general.capital_col_need') }}</th>
                        <th class="text-right">{{ trans('admin/purchase-orders/general.lease_qty') }}</th>
                        <th>{{ trans('admin/purchase-orders/general.forecast_model') }}</th>
                        <th class="text-right">{{ trans('admin/purchase-orders/general.capital_col_unit') }}</th>
                        <th class="text-right">{{ trans('admin/purchase-orders/general.capital_col_cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($newAsks as $row)
                    <tr>
                        <td>{{ $row['need'] }}</td>
                        <td class="text-right">{{ $row['qty'] }}</td>
                        <td style="white-space:normal;">{{ $row['model'] }}</td>
                        <td class="text-right">${{ number_format($row['unit'], 2) }}</td>
                        <td class="text-right">${{ number_format($row['cost'], 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th>{{ trans('admin/orders/general.total') }}</th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th class="text-right">${{ number_format($newAskTotal, 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>
</div>

{{-- Where the request landed once finance issued paper. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.capital_pos_title') }}</h3>
    </div>
    <div class="box-body">
        @if ($purchaseOrders->isEmpty())
            <p class="text-muted" style="margin:0;">{{ trans('admin/purchase-orders/general.capital_pos_none') }}</p>
        @else
            <ul class="list-unstyled" style="margin:0;">
                @foreach ($purchaseOrders as $po)
                    <li style="margin-bottom:4px;">
                        <a href="{{ route('purchase-orders.show', $po) }}">{{ $po->po_number }}</a>
                        @if ($po->title)<span class="text-muted">· {{ $po->title }}</span>@endif
                        @if ($po->budget)<span class="text-muted">· ${{ number_format((float) $po->budget, 2) }}</span>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>

<style>
    .capital-table td, .capital-table th { white-space: nowrap; }
</style>

@stop
