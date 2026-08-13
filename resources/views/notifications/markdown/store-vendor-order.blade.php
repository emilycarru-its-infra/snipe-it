@component('mail::message')
<h2 style="text-align: left; margin: 0 0 12px; font-size: 18px;">{{ trans('mail.store_vendor_order_heading', ['references' => $references]) }}</h2>

<p style="text-align: left; margin: 0 0 14px;">{{ trans('mail.store_vendor_order_intro', ['supplier' => $supplier->name ?? trans('general.supplier')]) }}</p>

{{-- The same shape as the purchase-order email, because the reseller's desk
     reads both and should not have to learn two layouts. The difference is
     what we can honestly state: there is no quote and no PO yet, so the
     money column is our estimate and is labelled as one. --}}
@foreach ($orders as $order)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; font-size: 13px; margin: 0 0 8px;">
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.store_vendor_csv_reference') }}</td>
<td style="padding: 2px 0; text-align: left; font-weight: 700;">{{ $order->reference() }}</td>
</tr>
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.store_vendor_csv_account') }}</td>
<td style="padding: 2px 0; text-align: left;">{{ $order->fundingLabel() }}</td>
</tr>
@if ($order->lease_schedule)
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.store_vendor_csv_schedule') }}</td>
<td style="padding: 2px 0; text-align: left;">{{ $order->lease_schedule }}</td>
</tr>
@endif
@if ($order->user)
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.store_vendor_csv_requested_by') }}</td>
<td style="padding: 2px 0; text-align: left;">{{ $order->user->present()->fullName }}</td>
</tr>
@endif
</table>

{{-- Built from <table> elements rather than a markdown table: a catalog
     name is full of pipe characters ("MacBook Pro | 14" | M5 | …") and
     every one opens a new column, so the description truncates at the
     first pipe and its tail lands under whatever heading comes next. The
     reseller places orders off these part numbers — this is the one email
     in the system that must not garble a line. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse: collapse; font-size: 12px; margin: 0 0 18px;">
<thead>
<tr>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_quantity') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700;">{{ trans('mail.store_vendor_csv_description') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_mfr') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_edc') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_warranty') }}</th>
<th style="text-align: left; padding: 0 0 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_order_estimate_column') }}</th>
</tr>
</thead>
<tbody>
@foreach ($order->items as $line)
<tr>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-weight: 700;">{{ $line->quantity }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee;">
{{ $line->description }}
@if ($line->catalogItem?->bundle_url)
<br><a href="{{ $line->catalogItem->bundle_url }}" style="font-size: 11px;">{{ trans('mail.store_vendor_bundle_link') }}</a>
@endif
</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-family: ui-monospace, Menlo, monospace;">{{ $line->mfr_part_number ?: trans('mail.store_vendor_part_missing') }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-family: ui-monospace, Menlo, monospace;">{{ $line->vendor_sku ?: trans('mail.store_vendor_part_missing') }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; opacity: .8;">{{ $line->catalogItem?->warrantyLabel() ?: '—' }}</td>
<td style="text-align: left; padding: 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap;">{{ \App\Helpers\Helper::formatCurrencyOutput($line->unit_cost * $line->quantity) }}</td>
</tr>
@endforeach
<tr>
<td colspan="5" style="text-align: left; padding: 8px 12px 0 0; font-weight: 700;">{{ trans('mail.store_vendor_order_estimate_total') }}</td>
<td style="text-align: left; padding: 8px 0 0 0; font-weight: 700; white-space: nowrap;">{{ \App\Helpers\Helper::formatCurrencyOutput($order->total()) }}</td>
</tr>
</tbody>
</table>
@endforeach

{{ trans('mail.store_vendor_order_csv_note', ['lines' => $lineCount]) }}

{{ trans('mail.store_vendor_order_estimate_note', ['total' => \App\Helpers\Helper::formatCurrencyOutput($orders->sum(fn ($order) => $order->total()))]) }}

{{ trans('mail.store_vendor_order_reference', ['references' => $references]) }}

{{ trans('mail.store_vendor_order_footer') }}
@endcomponent
