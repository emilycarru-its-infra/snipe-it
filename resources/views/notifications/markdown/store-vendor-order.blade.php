@component('mail::message')
# {{ trans('mail.store_vendor_order_heading', ['id' => $order->id]) }}

{{ trans('mail.store_vendor_order_intro', ['supplier' => $supplier->name ?? trans('general.supplier')]) }}

<x-mail::table>

| {{ trans('mail.store_vendor_col_sku') }} | {{ trans('mail.store_vendor_col_mfr') }} | {{ trans('mail.store_vendor_col_desc') }} | {{ trans('mail.store_vendor_col_qty') }} |
| :- | :- | :- | -: |
@foreach ($order->items as $line)
| {{ $line->vendor_sku ?: '—' }} | {{ $line->mfr_part_number ?: '—' }} | {{ $line->description }} | {{ $line->quantity }} |
@endforeach
</x-mail::table>

{{ trans('mail.store_vendor_order_reference', ['id' => $order->id]) }}

{{ trans('mail.store_vendor_order_footer') }}
@endcomponent
