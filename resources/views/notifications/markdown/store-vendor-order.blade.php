@component('mail::message')
# {{ trans('mail.store_vendor_order_heading', ['references' => $references]) }}

{{ trans('mail.store_vendor_order_intro', ['supplier' => $supplier->name ?? trans('general.supplier')]) }}

<x-mail::table>

| {{ trans('mail.store_vendor_col_ref') }} | {{ trans('mail.store_vendor_col_sku') }} | {{ trans('mail.store_vendor_col_mfr') }} | {{ trans('mail.store_vendor_col_desc') }} | {{ trans('mail.store_vendor_col_qty') }} |
| :- | :- | :- | :- | -: |
@foreach ($orders as $order)
@foreach ($order->items as $line)
| ECU-STORE-{{ $order->id }} | {{ $line->vendor_sku ?: '—' }} | {{ $line->mfr_part_number ?: '—' }} | {{ $line->description }} | {{ $line->quantity }} |
@endforeach
@endforeach
</x-mail::table>

{{ trans('mail.store_vendor_order_reference', ['references' => $references]) }}

{{ trans('mail.store_vendor_order_footer') }}
@endcomponent
