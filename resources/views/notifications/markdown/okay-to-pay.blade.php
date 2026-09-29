@component('mail::message')
{{ trans('mail.okay_to_pay_greeting') }}

{{ trans('mail.okay_to_pay_intro', [
    'supplier' => $supplier->name ?? '',
    'invoice' => $invoice->invoice_number,
    'date' => optional($invoice->invoice_date)->toDateString() ?: '—',
    'schedule' => $schedule ?: '—',
]) }}

**{{ trans('mail.okay_to_pay_ok', ['invoice' => $invoice->invoice_number, 'total' => $amounts['total']]) }}**

<x-mail::table>

| {{ trans('mail.okay_to_pay_qty') }} | {{ trans('mail.okay_to_pay_description') }} | {{ trans('admin/hardware/form.serial') }} | {{ trans('mail.okay_to_pay_amount') }} |
| :- | :- | :- | -: |
@foreach ($lines as $line)
| {{ $line['quantity'] }} | {{ $line['description'] }} | {{ $line['serial'] ?: '—' }} | {{ $line['amount'] }} |
@endforeach

</x-mail::table>

<x-mail::table>

| | |
| :- | -: |
| {{ trans('mail.okay_to_pay_subtotal') }} | {{ $amounts['subtotal'] }} |
@if ((float) $invoice->shipping > 0)
| {{ trans('mail.okay_to_pay_shipping') }} | {{ $amounts['shipping'] }} |
@endif
| GST | {{ $amounts['gst'] }} |
@if ((float) $invoice->tax_pst > 0)
| PST | {{ $amounts['pst'] }} |
@endif
| **{{ trans('mail.okay_to_pay_total') }}** | **{{ $amounts['total'] }}** |

</x-mail::table>

{{ trans('mail.okay_to_pay_closing') }}

{{ $signature }}
@endcomponent
