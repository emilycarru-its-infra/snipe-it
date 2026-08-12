@extends('layouts/default')

@section('title')
    {{ $group['contract_name'] ?: $group['contract_id'] }}
    @parent
@stop

{{-- One lease, the whole story — the in-app mirror of the lessor's
     Exhibit A: the terms and factors up top, the device schedule beneath,
     each unit with its costs, rent and where it is now. --}}
@section('content')

<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ $group['contract_name'] ?: $group['contract_id'] }}
            <span class="text-muted" style="font-weight:normal;">· {{ $group['contract_id'] }}</span>
        </h3>
        <div class="box-tools pull-right">
            <span class="label label-{{ $closure['is_closed'] ? 'default' : 'success' }}">
                {{ $closure['is_closed']
                    ? trans('admin/purchase-orders/general.aro_status_contractual')
                    : trans('admin/purchase-orders/general.lease_detail_open', ['open' => $closure['open'], 'total' => count($group['assets'])]) }}
            </span>
        </div>
    </div>
    <div class="box-body">
        <div class="row">
            <div class="col-md-6">
                <h4 style="margin-top:0;">{{ trans('admin/purchase-orders/general.lease_detail_terms') }}</h4>
                <dl class="lease-terms">
                    <dt>{{ trans('admin/purchase-orders/general.lease_provider') }}</dt>
                    <dd>{{ $group['provider'] ?: '—' }}</dd>
                    <dt>{{ trans('admin/purchase-orders/general.lease_ownership') }}</dt>
                    <dd>{{ collect($group['ownership_counts'])->map(fn ($count, $type) => "($count) $type")->implode(', ') ?: '—' }}</dd>
                    <dt>{{ trans('admin/purchase-orders/general.lease_detail_term_start') }}</dt>
                    <dd>{{ $basis ? $basis['start']->toDateString() : '—' }}</dd>
                    <dt>{{ trans('admin/purchase-orders/general.lease_end_date') }}</dt>
                    <dd>
                        {{ $group['lease_end_date'] ?: '—' }}
                        @if ($term?->end_date && $group['lease_end_date'] && $term->end_date->format('Y-m-d') !== substr((string) $group['lease_end_date'], 0, 10))
                            <span class="text-muted">{{ trans('admin/purchase-orders/general.lease_detail_register_end', ['date' => $term->end_date->format('Y-m-d')]) }}</span>
                        @endif
                    </dd>
                    @if ($basis)
                        <dt>{{ trans('admin/purchase-orders/general.lease_detail_term_months') }}</dt>
                        <dd>{{ max(1, (int) round($basis['start']->diffInDays($basis['end']) / 30.44)) }}</dd>
                        <dt>{{ trans('admin/purchase-orders/general.extension_monthly_cost') }}</dt>
                        <dd>${{ number_format($basis['monthly'], 2) }}
                            @if ($basis['estimated'])<span class="label label-default">{{ trans('admin/purchase-orders/general.price_estimate') }}</span>@endif
                        </dd>
                    @endif
                </dl>
            </div>
            <div class="col-md-6">
                <h4 style="margin-top:0;">{{ trans('admin/purchase-orders/general.lease_detail_funding') }}</h4>
                <dl class="lease-terms">
                    <dt>{{ trans('admin/purchase-orders/general.lease_equipment_cost') }}</dt>
                    <dd>${{ number_format($totals['equipment'], 2) }}</dd>
                    <dt>{{ trans('admin/purchase-orders/general.lease_warranty_cost') }}</dt>
                    <dd>${{ number_format($totals['soft'], 2) }}</dd>
                    <dt>{{ trans('admin/purchase-orders/general.lease_total_cost') }}</dt>
                    <dd><strong>${{ number_format($totals['equipment'] + $totals['soft'], 2) }}</strong></dd>
                    @if ($totals['buyout'] > 0)
                        <dt>{{ trans('admin/purchase-orders/general.lease_detail_buyout') }}</dt>
                        <dd>${{ number_format($totals['buyout'], 2) }}</dd>
                    @endif
                    <dt>{{ trans('admin/purchase-orders/general.lease_pos') }}</dt>
                    <dd>
                        @forelse ($poNumbers as $poNumber)
                            <a href="{{ route('purchase-orders.show', $poNumber) }}">{{ $poNumber }}</a>@if (! $loop->last), @endif
                        @empty — @endforelse
                    </dd>
                    <dt>{{ trans('admin/purchase-orders/general.lease_cdw_orders') }}</dt>
                    <dd>{{ implode(', ', $cdwOrders) ?: '—' }}</dd>
                </dl>
            </div>
        </div>

        <style>
            .lease-terms {
                display: grid;
                grid-template-columns: max-content minmax(0, 1fr);
                column-gap: 18px;
                row-gap: 4px;
                margin: 0;
            }
            .lease-terms dt { text-align: left; font-weight: 700; white-space: nowrap; }
            .lease-terms dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
            .lease-schedule td, .lease-schedule th { white-space: nowrap; }
        </style>
    </div>
</div>

{{-- The device schedule — one row per unit, serial order, like the
     lessor's own Exhibit. --}}
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ trans('admin/purchase-orders/general.lease_detail_schedule') }}
            — {{ count($devices) }}
        </h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-striped table-condensed lease-schedule">
            <thead>
                <tr>
                    <th>{{ trans('admin/hardware/table.serial') }}</th>
                    <th>{{ trans('admin/hardware/table.asset_tag') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.forecast_model') }}</th>
                    <th>{{ trans('admin/hardware/table.checkoutto') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.lease_detail_commencement') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.lease_equipment_cost') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.lease_warranty_cost') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.lease_detail_monthly_unit') }}</th>
                    <th class="text-right">{{ trans('admin/purchase-orders/general.lease_detail_buyout') }}</th>
                    <th>{{ trans('admin/purchase-orders/general.lease_ownership') }}</th>
                    <th>{{ trans('admin/hardware/table.status') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($devices as $device)
                @php($asset = $device['asset'])
                <tr>
                    <td><a href="{{ route('hardware.show', $asset->id) }}" class="js-lightbox">{{ $asset->serial ?: '—' }}</a></td>
                    <td>{{ $asset->asset_tag }}</td>
                    <td>{{ $asset->model?->name ?: '—' }}</td>
                    <td>
                        @php($holder = $asset->holderUser())
                        @php($holderLocation = $asset->holderLocation())
                        @if ($holder)
                            <a href="{{ route('users.show', $holder) }}">{{ $holder->present()->fullName }}</a>
                        @elseif ($holderLocation)
                            {{ $holderLocation->name }}
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td>{{ optional($asset->purchase_date)->format('Y-m-d') ?: '—' }}</td>
                    <td class="text-right">{{ $device['equipment'] > 0 ? '$'.number_format($device['equipment'], 2) : '—' }}</td>
                    <td class="text-right">{{ $device['soft'] > 0 ? '$'.number_format($device['soft'], 2) : '—' }}</td>
                    <td class="text-right">{{ $device['rent'] > 0 ? '$'.number_format($device['rent'], 2) : '—' }}</td>
                    <td class="text-right">{{ $device['buyout'] > 0 ? '$'.number_format($device['buyout'], 2) : '—' }}</td>
                    <td>{{ $device['ownership'] ?: '—' }}</td>
                    <td>{{ $asset->status?->name ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5">{{ trans('admin/orders/general.total') }}</th>
                    <th class="text-right">${{ number_format($totals['equipment'], 2) }}</th>
                    <th class="text-right">${{ number_format($totals['soft'], 2) }}</th>
                    <th class="text-right">${{ number_format($totals['rent'], 2) }}</th>
                    <th class="text-right">{{ $totals['buyout'] > 0 ? '$'.number_format($totals['buyout'], 2) : '—' }}</th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

@if ($decisions->isNotEmpty())
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/purchase-orders/general.lease_detail_decisions') }}</h3>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-striped table-condensed">
            <thead>
                <tr>
                    <th>{{ trans('admin/lease-decisions/general.decision_type') }}</th>
                    <th>{{ trans('admin/lease-decisions/general.status') }}</th>
                    <th class="text-right">{{ trans('admin/lease-decisions/general.amount') }}</th>
                    <th>{{ trans('admin/lease-decisions/general.decision_date') }}</th>
                    <th>{{ trans('general.notes') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($decisions as $decision)
                <tr>
                    <td>{{ trans('admin/lease-decisions/general.type_'.$decision->decision_type) }}</td>
                    <td>{{ trans('admin/lease-decisions/general.status_'.$decision->status) }}</td>
                    <td class="text-right">{{ $decision->amount !== null ? '$'.number_format((float) $decision->amount, 2) : '—' }}</td>
                    <td>{{ optional($decision->decision_date)->format('Y-m-d') ?: '—' }}</td>
                    <td style="white-space:normal;">{{ $decision->notes }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@stop
