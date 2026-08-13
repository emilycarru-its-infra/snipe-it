@component('mail::message')
# {{ trans('mail.store_order_'.$event.'_heading') }}

{{ trans('mail.store_order_greeting', ['name' => $target->present()->fullName]) }}

{{ trans('mail.store_order_'.$event.'_intro') }}

{{-- Any decision note, not only a refusal. An approval note is written to
     be read, and this printed it nowhere for approvals — so the note sat on
     the order while the requester got a bare "approved". --}}
@if ($note && in_array($event, ['approved', 'declined'], true))
> {{ $note }}
@endif

{{-- One card per line, matching the order card the requester built in the
     store. A markdown table cannot carry this: a catalog name is full of
     pipe characters ("MacBook Pro | 16" | M5 Max | …"), and every one of
     them opened a new column — which is how a screen size ended up under
     the heading "Qty". --}}
@foreach ($order->items as $line)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 0 0 12px; border: 1px solid #d8d8dc; border-radius: 10px;">
<tr>
<td style="padding: 16px;">

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
<tr>
@if ($line->catalogItem?->storeImageUrl())
<td width="72" valign="top" style="padding-right: 14px;">
<img src="{{ $line->catalogItem->storeImageUrl() }}" width="72" alt="" style="width: 72px; max-width: 72px; height: auto; display: block;">
</td>
@endif
<td valign="top">
<div style="font-size: 16px; font-weight: 700;">{{ $line->catalogItem?->family ?: $line->description }}</div>
@if ($line->mfr_part_number || $line->vendor_sku)
<div style="font-size: 11px; letter-spacing: .04em; opacity: .6; font-family: ui-monospace, Menlo, monospace;">{{ $line->mfr_part_number ?: $line->vendor_sku }}</div>
@endif
</td>
</tr>
</table>

@php($specs = $line->catalogItem?->specList() ?: [])
@if ($specs)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 12px; font-size: 13px;">
@foreach ($specs as $label => $value)
<tr>
<td width="40%" style="padding: 3px 0; opacity: .6;">{{ $label }}</td>
<td style="padding: 3px 0;">{{ $value }}</td>
</tr>
@endforeach
</table>
@else
<div style="margin-top: 10px; font-size: 13px; opacity: .75;">{{ $line->description }}</div>
@endif

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top: 14px; font-size: 13px;">
<tr>
<td>{{ trans('mail.store_order_col_qty') }} × {{ $line->quantity }}</td>
<td align="right" style="font-weight: 700;">{{ \App\Helpers\Helper::formatCurrencyOutput($line->lineTotal()) }}</td>
</tr>
</table>

</td>
</tr>
</table>
@endforeach

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 4px 0 8px; font-size: 15px;">
<tr>
<td style="font-weight: 700;">{{ trans('mail.store_order_total') }}</td>
<td align="right" style="font-weight: 700;">{{ \App\Helpers\Helper::formatCurrencyOutput($order->items->sum(fn ($line) => $line->lineTotal())) }}</td>
</tr>
</table>

{{ trans('mail.store_order_estimate_note') }}

@if ($order->notes)
**{{ trans('mail.store_order_notes_label') }}**
{{ $order->notes }}
@endif

@if ($event === 'shipped' && $tracking)
{{ trans('mail.store_order_tracking', ['tracking' => $tracking]) }}
@endif

@if (! empty($serials))
{{ trans('mail.store_order_serials') }} {{ implode(', ', $serials) }}
@endif

@component('mail::button', ['url' => route('store.orders')])
{{ trans('mail.store_order_button') }}
@endcomponent

{{ trans('mail.store_order_footer') }}
@endcomponent
