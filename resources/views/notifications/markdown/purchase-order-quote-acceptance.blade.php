@component('mail::message')
<div style="text-align: left;">

{{-- Markup rather than "## …" so it inherits the left alignment of this block:
     the layout centres headings by default, and every line here should start
     at the same left edge as the figures under it. --}}
<h2 style="text-align: left; margin: 0 0 12px; font-size: 18px;">{{ trans('mail.purchase_order_quote_accepted_heading', ['quote' => $quote ?: $reference]) }}</h2>

<p style="text-align: left; margin: 0 0 14px;">{{ trans('mail.purchase_order_quote_accepted_intro', ['supplier' => $supplier->name ?? trans('general.supplier'), 'quote' => $quote ?: $reference]) }}</p>

{{-- The references in a table, not a sentence: their desk is matching this
     against the quote on their screen. Their number leads, ours follows. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; font-size: 13px; margin: 0 0 18px;">
@if (filled($quote))
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.requisition_vendor_order_field_quote') }}</td>
<td style="padding: 2px 0; text-align: left; font-weight: 700;">{{ $quote }}</td>
</tr>
@endif
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.requisition_vendor_order_field_po') }}</td>
<td style="padding: 2px 0; text-align: left; font-weight: 700;">{{ $reference }}</td>
</tr>
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.store_vendor_csv_account') }}</td>
<td style="padding: 2px 0; text-align: left;">{{ $order->fundingDescription() }}</td>
</tr>
@if (filled($order->lease_schedule))
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.store_vendor_csv_schedule') }}</td>
<td style="padding: 2px 0; text-align: left;">{{ $order->lease_schedule }}</td>
</tr>
@endif
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.purchase_order_quote_accepted_field_total') }}</td>
<td style="padding: 2px 0; text-align: left; font-weight: 700;">{{ \App\Helpers\Helper::formatCurrencyOutput($total) }}</td>
</tr>
</table>

<p style="text-align: left; margin: 0;">{{ trans('mail.purchase_order_quote_accepted_footer', ['reference' => $reference]) }}</p>

</div>
@endcomponent
