@component('mail::message')
# {{ trans('mail.requisition_vendor_order_heading', ['reference' => $reference]) }}

{{ trans('mail.requisition_vendor_order_intro', ['supplier' => $supplier->name ?? trans('general.supplier'), 'reference' => $reference]) }}

{{ trans('mail.requisition_vendor_order_account', [
    'account' => $requisition->fundingLabel(),
    'schedule' => $requisition->lease_schedule ? trans('mail.requisition_vendor_order_account_schedule', ['schedule' => $requisition->lease_schedule]) : '',
]) }}

@if (filled($requisition->quote_number))
{{ trans('mail.requisition_vendor_order_quote', [
    'quote' => $requisition->quote_number,
    'total' => \App\Helpers\Helper::formatCurrencyOutput($requisition->vendorTotal()),
]) }}
@endif

{{ trans_choice('mail.requisition_vendor_order_csv_note', $lineCount, ['lines' => $lineCount]) }}

{{-- Built from <table> elements rather than a markdown table. A markdown
     table cannot carry a product line at all: a catalog name is full of pipe
     characters ("MacBook Pro | 14" | M5 | …") and every one of them opens a
     new column, so the description truncates at the first pipe and its tail
     lands under whatever heading comes next. The reseller places this order
     off these part numbers — the one email in the system that must not
     garble a line is this one. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 0 0 18px; border: 1px solid #d8d8dc; border-radius: 10px;">
<tr>
<td style="padding: 16px;">

@foreach ($requisition->items as $line)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="{{ $loop->first ? '' : 'margin-top: 14px; border-top: 1px solid #ebebee;' }} font-size: 13px;">
<tr>
<td style="{{ $loop->first ? '' : 'padding-top: 12px;' }}">
<div style="font-weight: 700;">{{ $line->description }}</div>
<div style="padding-top: 4px; font-family: ui-monospace, Menlo, monospace; font-size: 12px;">
{{-- A line with no manufacturer's number says nothing about one: freight and
     the recycling fee have no manufacturer, and "not on file" would read as a
     gap in our record rather than the nature of the line. Products cannot get
     here without both numbers — the send refuses them. --}}
@if (filled($line->mfr_part_number))
{{ trans('mail.store_vendor_csv_mfr') }} <strong>{{ $line->mfr_part_number }}</strong>
&nbsp;·&nbsp;
@endif
{{ trans('mail.store_vendor_csv_edc') }} <strong>{{ $line->vendor_sku }}</strong>
</div>
</td>
<td align="right" valign="top" style="{{ $loop->first ? '' : 'padding-top: 12px;' }} white-space: nowrap; font-weight: 700;">&times;&nbsp;{{ $line->quantity }}</td>
<td align="right" valign="top" style="{{ $loop->first ? '' : 'padding-top: 12px;' }} white-space: nowrap; padding-left: 14px;">{{ \App\Helpers\Helper::formatCurrencyOutput($line->unit_cost) }}</td>
</tr>
</table>
@endforeach

</td>
</tr>
</table>

@if (filled($requisition->quote_number))
{{ trans('mail.requisition_vendor_order_total', ['total' => \App\Helpers\Helper::formatCurrencyOutput($requisition->vendorTotal())]) }}
@else
{{-- Unquoted orders are normal here: ours are estimates by design and a few
     percent of drift consumes budget rather than invalidating the order. Say
     so plainly, so their desk quotes the current figure instead of treating
     our price list as agreed. --}}
{{ trans('mail.requisition_vendor_order_estimate_note', ['total' => \App\Helpers\Helper::formatCurrencyOutput($requisition->total())]) }}
@endif

@if (filled($requisition->printer_comments))
{{ $requisition->printer_comments }}
@endif

{{ trans('mail.requisition_vendor_order_footer') }}
@endcomponent
