@component('mail::message')
<div style="text-align: left;">

<p style="text-align: left; margin: 0 0 14px;">{{ trans('mail.order_eta_request_intro', ['supplier' => $supplier->name ?? trans('general.supplier'), 'reference' => $reference]) }}</p>

@if (filled($note))
<p style="text-align: left; margin: 0 0 14px; white-space: pre-wrap;">{{ $note }}</p>
@endif

{{-- Their number first: it is what their desk searches on. Ours follows so
     the reply can be matched either way. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; font-size: 13px; margin: 0 0 18px;">
@if (filled($order->vendor_order_number))
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.order_eta_request_field_vendor_order') }}</td>
<td style="padding: 2px 0; text-align: left; font-weight: 700;">{{ $order->vendor_order_number }}</td>
</tr>
@endif
@if (filled($order->quote_number))
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.requisition_vendor_order_field_quote') }}</td>
<td style="padding: 2px 0; text-align: left;">{{ $order->quote_number }}</td>
</tr>
@endif
@if (filled($purchase_order))
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.requisition_vendor_order_field_po') }}</td>
<td style="padding: 2px 0; text-align: left;">{{ $purchase_order }}</td>
</tr>
@endif
@if (! filled($order->vendor_order_number))
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.order_eta_request_field_order') }}</td>
<td style="padding: 2px 0; text-align: left;">{{ $order->order_number }}</td>
</tr>
@endif
@if ($order->vendor_sent_at || $order->order_date)
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.order_eta_request_field_ordered') }}</td>
<td style="padding: 2px 0; text-align: left;">{{ ($order->vendor_sent_at ?? $order->order_date)?->format('Y-m-d') }}</td>
</tr>
@endif
</table>

{{-- Only what is still to arrive, in <table> rather than markdown because
     catalog names are full of pipes. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse: collapse; font-size: 12px; margin: 0 0 14px;">
<thead>
<tr>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_quantity') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700;">{{ trans('mail.store_vendor_csv_description') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_mfr') }}</th>
<th style="text-align: left; padding: 0 0 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_edc') }}</th>
</tr>
</thead>
<tbody>
@foreach ($lines as $line)
<tr>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-weight: 700;">{{ $line->quantity }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee;">{{ $line->description }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-family: ui-monospace, Menlo, monospace;">{{ $line->mfr_part_number ?: '—' }}</td>
<td style="text-align: left; padding: 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-family: ui-monospace, Menlo, monospace;">{{ $line->vendor_sku ?: '—' }}</td>
</tr>
@endforeach
</tbody>
</table>

<p style="text-align: left; margin: 0 0 14px;">{{ trans('mail.order_eta_request_ask') }}</p>

@if (filled($sender))
<p style="text-align: left; margin: 0;">{{ trans('mail.order_eta_request_signoff') }}<br>{{ $sender }}</p>
@endif

</div>
@endcomponent
