@component('mail::message')
# {{ trans('mail.store_order_'.$event.'_heading') }}

{{ trans('mail.store_order_greeting', ['name' => $target->present()->fullName]) }}

{{ trans('mail.store_order_'.$event.'_intro') }}

@if ($event === 'declined' && $note)
> {{ $note }}
@endif

<x-mail::table>

| {{ trans('mail.store_order_col_item') }} | {{ trans('mail.store_order_col_qty') }} |
| :- | :- |
@foreach ($order->items as $line)
| {{ $line->description }} | {{ $line->quantity }} |
@endforeach
</x-mail::table>

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
