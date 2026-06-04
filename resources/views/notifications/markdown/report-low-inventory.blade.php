@component('mail::message')
# ⚠️ {{ trans('mail.Low_Inventory_Report') }}

{{ trans_choice('mail.low_inventory_alert', $count ?? count($items)) }}

@foreach ($groups as $group)
@if ($group['manufacturer'])
**{{ $group['model_name'] }}** — {{ $group['manufacturer'] }}@if (! is_null($group['printers_count'])) · {{ $group['printers_count'] }} {{ \Illuminate\Support\Str::plural('printer', $group['printers_count']) }}@endif
@else
**{{ $group['model_name'] }}**@if (! is_null($group['printers_count'])) — {{ $group['printers_count'] }} {{ \Illuminate\Support\Str::plural('printer', $group['printers_count']) }}@endif
@endif

@component('mail::table')
| {{ trans('mail.name') }} | {{ trans('mail.current_QTY') }} | {{ trans('mail.min_QTY') }} |
|:---------|:---------:|:---------:|
@foreach ($group['items'] as $item)
| <a href="{{ route(($item['type'] ?? 'consumables').'.show', $item['id']) }}">{{ $item['name'] }}</a> | {{ $item['remaining'] }} | {{ $item['min_amt'] }} |
@endforeach
@endcomponent

@endforeach
@endcomponent
