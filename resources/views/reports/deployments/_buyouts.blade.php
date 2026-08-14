{{-- Buyouts: devices leaving by purchase rather than pickup. Deliberately
     not fiscal-year scoped — an unpaid buyout from last spring is exactly
     what this table exists to keep visible.

     The buyer leads the row. A buyout is a person's decision about a device,
     not a device's decision about a person, and the asset link is the one
     field that is legitimately empty for a while — a request opened against
     a lease line before anybody has worked out which unit it is. Sorting the
     eye onto a dash was the wrong lede.

     Every row expands into a full editor for the record — who and what, the
     money, the stage — rather than the three fields the panel used to offer,
     because the fields it left out (the asset link, the buyer, the lessor)
     are exactly the ones a reconstructed buyout arrives missing.

     Needs $buyouts. --}}
@php($canEdit = auth()->user()?->can('requestBuyout', \App\Models\Asset::class))

@push('css')
<style>
    /* The caret points at what the click does: down to open, up once open. */
    .buyout-caret { display: inline-block; transition: transform .12s ease; }
    .buyout-toggle[aria-expanded="true"] .buyout-caret { transform: rotate(180deg); }
</style>
@endpush

<div class="box box-default decom-card">
    <div class="box-header with-border">
        <h3 class="box-title">{{ trans('admin/deployments/general.buyouts_title') }}
            <span class="text-muted" style="font-weight:normal; font-size:12px; margin-left:8px;">{{ trans('admin/deployments/general.buyouts_hint') }}</span>
        </h3>
    </div>
    <div class="box-body">
@if (count($buyouts['rows']) === 0)
    <p class="text-muted" style="margin:0;">{{ trans('admin/deployments/general.buyouts_none') }}</p>
@else
    <p class="text-muted" style="font-size:12px; margin:0 0 8px;">
        {{ trans('admin/deployments/general.buyouts_carrying', [
            'buyer' => '$'.number_format($buyouts['buyerTotal'], 2),
            'ecu' => '$'.number_format($buyouts['ecuTotal'], 2),
        ]) }}
        @if ($buyouts['overdueCount'] > 0)
            <strong class="text-danger" style="margin-left:8px;">{{ trans('admin/deployments/general.buyouts_overdue', ['count' => $buyouts['overdueCount']]) }}</strong>
        @endif
    </p>

    <div class="dp-scroll" style="max-height:520px;">
        <table class="table table-striped table-condensed" style="margin-bottom:0;">
            <thead>
                <tr>
                    <th style="width:24px;"></th>
                    <th>{{ trans('admin/deployments/general.buyout_col_buyer') }}</th>
                    <th>{{ trans('admin/deployments/general.buyout_col_asset') }}</th>
                    <th>{{ trans('admin/deployments/general.buyout_col_lessor') }}</th>
                    <th>{{ trans('admin/deployments/general.buyout_col_status') }}</th>
                    <th>{{ trans('admin/deployments/general.buyout_col_waiting') }}</th>
                    <th class="text-right">{{ trans('admin/deployments/general.buyout_col_age') }}</th>
                    <th class="text-right">{{ trans('admin/deployments/general.buyout_col_quote') }}</th>
                    <th class="text-right">{{ trans('admin/deployments/general.buyout_col_split') }}</th>
                    <th>{{ trans('admin/deployments/general.buyout_col_invoice') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($buyouts['rows'] as $row)
                    <tr @unless ($row['open']) class="text-muted" @endunless>
                        <td>
                            <a href="#buyout-{{ $row['id'] }}" class="buyout-toggle" data-toggle="collapse" role="button"
                               aria-expanded="false" aria-controls="buyout-{{ $row['id'] }}"
                               title="{{ trans('admin/deployments/general.buyout_edit') }}">
                                <i class="fas fa-caret-down buyout-caret" aria-hidden="true"></i>
                            </a>
                        </td>
                        <td>{{ $row['buyer'] ?: '—' }}</td>
                        <td>
                            @if ($row['asset_id'])
                                <a href="{{ route('hardware.show', $row['asset_id']) }}" class="js-lightbox">{{ $row['asset_tag'] ?: $row['serial'] }}</a>
                            @else
                                {{ $row['asset_tag'] ?: '—' }}
                            @endif
                            <span class="text-muted" style="font-size:11.5px;">{{ $row['model'] }}</span>
                        </td>
                        <td>{{ $row['lessor'] ?: '—' }}</td>
                        <td>{{ trans('admin/deployments/general.buyout_status_'.$row['status']) }}</td>
                        <td class="text-muted" style="font-size:12px;">{{ $row['waiting_on'] ? trans($row['waiting_on']) : '—' }}</td>
                        <td class="text-right">{{ $row['open'] ? trans('admin/deployments/general.buyout_age_days', ['days' => $row['age']]) : '—' }}</td>
                        <td class="text-right">
                            {{ $row['quote_total'] !== null ? '$'.number_format((float) $row['quote_total'], 2) : '—' }}
                            @if ($row['quote_count'] > 1)
                                <span class="text-muted" style="font-size:11px;">{{ trans('admin/deployments/general.buyout_superseded', ['count' => $row['quote_count']]) }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            {{ $row['buyer_amount'] !== null ? '$'.number_format((float) $row['buyer_amount'], 2) : '—' }}
                            <span class="text-muted">/ {{ $row['ecu_amount'] !== null ? '$'.number_format((float) $row['ecu_amount'], 2) : '—' }}</span>
                        </td>
                        <td @if ($row['overdue']) class="text-danger" @endif>
                            {{ $row['invoice_number'] ?: '—' }}
                            @if ($row['invoice_due_date'])
                                <span style="font-size:11.5px;">{{ $row['invoice_due_date'] }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr class="collapse" id="buyout-{{ $row['id'] }}">
                        <td colspan="10" style="background:rgba(127,127,127,.06);">
                            @if (! $canEdit)
                                <p class="text-muted" style="margin:0;">{{ trans('general.insufficient_permissions') }}</p>
                            @else
                                @include('reports.deployments._buyout-actions', ['row' => $row])
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
    </div>
</div>
