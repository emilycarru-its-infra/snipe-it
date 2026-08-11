@component('mail::message')
<div style="text-align: left;">

{{-- Written as markup rather than "## …" so it inherits the left alignment of
     this block: the layout centres headings by default, and on an order every
     line should start at the same left edge as the numbers under it. --}}
<h2 style="text-align: left; margin: 0 0 12px; font-size: 18px;">{{ trans('mail.requisition_vendor_order_heading', ['reference' => $reference]) }}</h2>

<p style="text-align: left; margin: 0 0 14px;">{{ trans('mail.requisition_vendor_order_intro', ['supplier' => $supplier->name ?? trans('general.supplier'), 'reference' => $reference]) }}</p>

{{-- Every number in a table, nothing in a sentence: a purchaser reading this is
     checking figures against a quote, and a figure buried in prose has to be
     hunted for. The vendor's own quote leads, because that is the reference
     their desk searches on before ours. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; font-size: 13px; margin: 0 0 18px;">
@if (filled($order->quote_number))
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.requisition_vendor_order_field_quote') }}</td>
<td style="padding: 2px 0; text-align: left; font-weight: 700;">{{ $order->quote_number }}</td>
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
</table>

{{-- One row per line, in columns, because the reseller's desk reads down a
     column: quantity first (it is what they pick), then the product, then the
     two part numbers that identify it, then the money. Built from <table>
     rather than a markdown table because a catalog name is full of pipe
     characters ("MacBook Pro | 14" | M5 | …") and every one of them would open
     a new column — the description would truncate at the first pipe and its
     tail would land under the wrong heading. This is the one email that must
     not garble a line. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse: collapse; font-size: 12px; margin: 0 0 14px;">
<thead>
<tr>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_quantity') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700;">{{ trans('mail.store_vendor_csv_description') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_mfr') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.store_vendor_csv_edc') }}</th>
<th style="text-align: left; padding: 0 12px 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.requisition_vendor_csv_unit_cost_short') }}</th>
<th style="text-align: left; padding: 0 0 5px 0; border-bottom: 1px solid #d8d8dc; font-weight: 700; white-space: nowrap;">{{ trans('mail.requisition_vendor_csv_line_total_short') }}</th>
</tr>
</thead>
<tbody>
@foreach ($lines as $line)
<tr>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-weight: 700;">{{ $line->quantity }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee;">{{ $line->description }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-family: ui-monospace, Menlo, monospace;">{{ $line->mfr_part_number ?: '—' }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-family: ui-monospace, Menlo, monospace;">{{ $line->vendor_sku ?: '—' }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap;">{{ \App\Helpers\Helper::formatCurrencyOutput($line->unit_cost) }}</td>
<td style="text-align: left; padding: 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap;">{{ \App\Helpers\Helper::formatCurrencyOutput($line->lineTotal()) }}</td>
</tr>
@endforeach
<tr>
<td colspan="4" style="text-align: left; padding: 8px 12px 0 0;"></td>
<td style="text-align: left; padding: 8px 12px 0 0; white-space: nowrap; font-weight: 700;">{{ trans('mail.requisition_vendor_order_field_total') }}</td>
<td style="text-align: left; padding: 8px 0 0; white-space: nowrap; font-weight: 700;">{{ \App\Helpers\Helper::formatCurrencyOutput($order->vendorTotal()) }}</td>
</tr>
</tbody>
</table>

@if ($specialLines->isNotEmpty())
<p style="text-align: left; margin: 0 0 14px;">{{ trans_choice('mail.requisition_vendor_order_special_lines', $specialLines->count(), ['count' => $specialLines->count()]) }}</p>
@endif

@if (! filled($order->quote_number))
{{-- Unquoted orders are normal: ours are estimates by design and a few percent
     of drift consumes budget rather than invalidating the order. Say so plainly,
     so their desk quotes the current figure instead of treating our price list
     as agreed. --}}
<p style="text-align: left; margin: 0 0 14px;">{{ trans('mail.requisition_vendor_order_estimate_note') }}</p>
@endif

{{-- The requisition's printer comments are deliberately NOT here. They are our
     own keying notes — funding source, tax treatment, the subtotal arithmetic —
     written for whoever types the order into Colleague, and none of it is the
     vendor's business or their instruction. Delivery detail reaches them on the
     attached purchase order, which is the document they act on. --}}
<p style="text-align: left; margin: 0;">{{ trans_choice('mail.requisition_vendor_order_csv_note', $lineCount, ['lines' => $lineCount]) }}</p>

</div>
@endcomponent
