@component('mail::message')
{{ trans('mail.okay_to_pay_greeting') }}

{{ trans('mail.okay_to_pay_batch_intro', ['order' => $order->order_number, 'supplier' => $order->supplier?->name, 'schedule' => $order->lease_schedule]) }}

<x-mail::table>

| {{ trans('admin/orders/general.invoice_number') }} | {{ trans('admin/orders/general.invoice_date') }} | {{ trans('mail.okay_to_pay_subtotal') }} | GST | PST | {{ trans('mail.okay_to_pay_shipping') }} | {{ trans('mail.okay_to_pay_total') }} |
| :- | :- | -: | -: | -: | -: | -: |
@foreach ($sections as $section)
| {{ $section['invoice']->invoice_number }} | {{ optional($section['invoice']->invoice_date)->toDateString() }} | {{ $section['amounts']['subtotal'] }} | {{ $section['amounts']['gst'] }} | {{ $section['amounts']['pst'] }} | {{ $section['amounts']['shipping'] }} | {{ $section['amounts']['total'] }} |
@endforeach

</x-mail::table>

**{{ trans('mail.okay_to_pay_batch_total', ['total' => $total]) }}**

@foreach ($sections as $section)
## {{ $section['invoice']->invoice_number }}

@if ($section['custom_body'] !== null)
{!! $section['custom_body'] !!}
@else
{{ trans('mail.okay_to_pay_ok', ['invoice' => $section['invoice']->invoice_number, 'total' => $section['amounts']['total']]) }}

<x-mail::table>

| {{ trans('mail.okay_to_pay_qty') }} | {{ trans('mail.okay_to_pay_description') }} | {{ trans('admin/hardware/form.serial') }} | {{ trans('mail.okay_to_pay_amount') }} |
| :- | :- | :- | -: |
@foreach ($section['lines'] as $line)
| {{ $line['quantity'] }} | {{ $line['description'] }} | {{ $line['serial'] ?: '—' }} | {{ $line['amount'] }} |
@endforeach

</x-mail::table>
@endif
@endforeach

{{ trans('mail.okay_to_pay_closing') }}

{{ $signature }}
@endcomponent
