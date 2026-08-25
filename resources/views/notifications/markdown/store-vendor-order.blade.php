@component('mail::message')
<h2 style="text-align: left; margin: 0 0 12px; font-size: 18px;">{{ trans('mail.store_vendor_order_heading') }}</h2>

<p style="text-align: left; margin: 0 0 14px;">{{ trans('mail.store_vendor_order_intro', ['supplier' => $supplier->name ?? trans('general.supplier')]) }}</p>

{{-- One parts list, not a transcript of our paperwork.
     A batch is sixteen store orders with one device each, and the desk that
     keys it needs none of that: the same model across many orders is one
     line with the quantity summed. What they do need is the purchase order
     their invoice must quote, the account the lines are placed against, and
     the lease schedule those ride — grouped, because a batch can span more
     than one set. See App\Services\VendorOrderLines. --}}
@foreach ($groups as $group)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; font-size: 13px; margin: 0 0 8px;">
@if ($group['purchase_order'])
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.store_vendor_csv_purchase_order') }}</td>
<td style="padding: 2px 0; text-align: left; font-weight: 700;">{{ $group['purchase_order'] }}</td>
</tr>
@endif
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.store_vendor_csv_account') }}</td>
<td style="padding: 2px 0; text-align: left; font-weight: 700;">{{ $group['account'] }}@if ($group['account_purpose'])<span style="font-weight: 400; opacity: .8;"> — {{ $group['account_purpose'] }}</span>@endif</td>
</tr>
@if ($group['schedule'])
<tr>
<td style="padding: 2px 18px 2px 0; text-align: left; white-space: nowrap; opacity: .7;">{{ trans('mail.store_vendor_csv_schedule') }}</td>
<td style="padding: 2px 0; text-align: left; font-weight: 700;">{{ $group['schedule'] }}</td>
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
@foreach ($group['lines'] as $line)
<tr>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-weight: 700;">{{ $line['quantity'] }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee;">
{{ $line['description'] }}
@if ($line['bundle_url'])
<br><a href="{{ $line['bundle_url'] }}" style="font-size: 11px;">{{ trans('mail.store_vendor_bundle_link') }}</a>
@endif
</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-family: ui-monospace, Menlo, monospace;">{{ $line['mfr_part_number'] ?: trans('mail.store_vendor_part_missing') }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; font-family: ui-monospace, Menlo, monospace;">{{ $line['vendor_sku'] ?: trans('mail.store_vendor_part_missing') }}</td>
<td style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap; opacity: .8;">{{ $line['warranty'] ?: '—' }}</td>
<td style="text-align: left; padding: 6px 0; border-bottom: 1px solid #ebebee; white-space: nowrap;">{{ \App\Helpers\Helper::formatCurrencyOutput($line['total']) }}</td>
</tr>
@endforeach
<tr>
<td colspan="5" style="text-align: left; padding: 8px 12px 0 0; font-weight: 700;">{{ trans('mail.store_vendor_order_estimate_total') }}</td>
<td style="text-align: left; padding: 8px 0 0 0; font-weight: 700; white-space: nowrap;">{{ \App\Helpers\Helper::formatCurrencyOutput($group['total']) }}</td>
</tr>
</tbody>
</table>
@endforeach

{{ trans('mail.store_vendor_order_csv_note', ['lines' => $lineCount]) }}

{{ trans('mail.store_vendor_order_estimate_note', ['total' => \App\Helpers\Helper::formatCurrencyOutput($orders->sum(fn ($order) => $order->total()))]) }}

{{ trans('mail.store_vendor_order_reference') }}

{{ trans('mail.store_vendor_order_footer') }}
@endcomponent
