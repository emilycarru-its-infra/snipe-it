@component('mail::message')
# {{ trans('mail.store_vendor_order_heading', ['references' => $references]) }}

{{ trans('mail.store_vendor_order_intro', ['supplier' => $supplier->name ?? trans('general.supplier')]) }}

{{ trans('mail.store_vendor_order_csv_note', ['lines' => $lineCount]) }}

{{-- One block per order, built from <table> elements rather than a
     markdown table. A markdown table cannot carry a product line at all:
     a catalog name is full of pipe characters ("MacBook Pro | 14" | M5 |
     …") and every one of them opens a new column, so the description
     truncates at the first pipe and its tail lands under whatever heading
     comes next. The reseller places orders off these part numbers — the
     one email in the system that must not garble a line is this one. --}}
@foreach ($orders as $order)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 0 0 18px; border: 1px solid #d8d8dc; border-radius: 10px;">
<tr>
<td style="padding: 16px;">

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size: 13px;">
<tr>
<td style="font-size: 15px; font-weight: 700;">ECU-STORE-{{ $order->id }}</td>
<td align="right" style="opacity: .7;">{{ $order->fundingLabel() }}</td>
</tr>
@if ($order->user)
<tr>
<td colspan="2" style="padding-top: 2px; opacity: .7;">{{ trans('mail.store_vendor_order_line_for', ['name' => $order->user->present()->fullName]) }}</td>
</tr>
@endif
</table>

@foreach ($order->items as $line)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 14px; border-top: 1px solid #ebebee; font-size: 13px;">
<tr>
<td style="padding-top: 12px;">
<div style="font-weight: 700;">{{ $line->description }}</div>
<div style="padding-top: 4px; font-family: ui-monospace, Menlo, monospace; font-size: 12px;">
{{ trans('mail.store_vendor_csv_mfr') }} <strong>{{ $line->mfr_part_number ?: trans('mail.store_vendor_part_missing') }}</strong>
&nbsp;·&nbsp;
{{ trans('mail.store_vendor_csv_edc') }} <strong>{{ $line->vendor_sku ?: trans('mail.store_vendor_part_missing') }}</strong>
</div>
@if ($line->catalogItem?->warrantyLabel())
<div style="padding-top: 4px; opacity: .7;">{{ trans('mail.store_vendor_csv_warranty') }}: {{ $line->catalogItem->warrantyLabel() }}</div>
@endif
@if ($line->catalogItem?->bundle_url)
<div style="padding-top: 4px;"><a href="{{ $line->catalogItem->bundle_url }}">{{ trans('mail.store_vendor_bundle_link') }}</a></div>
@endif
</td>
<td align="right" valign="top" style="padding-top: 12px; white-space: nowrap; font-weight: 700;">&times;&nbsp;{{ $line->quantity }}</td>
</tr>
</table>
@endforeach

</td>
</tr>
</table>
@endforeach

{{ trans('mail.store_vendor_order_estimate_note', ['total' => \App\Helpers\Helper::formatCurrencyOutput($orders->sum(fn ($order) => $order->total()))]) }}

{{ trans('mail.store_vendor_order_reference', ['references' => $references]) }}

{{ trans('mail.store_vendor_order_footer') }}
@endcomponent
