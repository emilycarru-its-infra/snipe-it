@php
    $heading = match ($window) {
        '14d'     => trans('mail.contract_renewal_alert_14d_heading', ['count' => $contracts->count()]),
        'expired' => trans('mail.contract_renewal_alert_expired_heading', ['count' => $contracts->count()]),
        default   => trans('mail.contract_renewal_alert_30d_heading', ['count' => $contracts->count()]),
    };
    $emoji = $window === 'expired' ? '🚨' : ($window === '14d' ? '⏰' : '⚠️');
@endphp
@component('mail::message')
# {{ $emoji }} {{ $heading }}

<x-mail::table>

| | {{ trans('admin/contracts/general.contract_number') }} | {{ trans('general.name') }} | {{ trans('admin/contracts/general.end_date') }} | {{ trans('admin/contracts/general.total_cost') }} | {{ trans('general.supplier') }} |
| :- | :- | :- | :- | :- | :- |
@foreach ($contracts as $contract)
| {{ $emoji }} | {{ $contract->contract_number }} | <a href="{{ route('contracts.show', $contract->id) }}">{{ $contract->name }}</a> | {{ optional($contract->end_date)->toDateString() }} | {{ $contract->total_cost ? '$'.\App\Helpers\Helper::formatCurrencyOutput($contract->total_cost).' '.$contract->currency : '—' }} | {{ $contract->supplier->name ?? '—' }} |
@endforeach
</x-mail::table>

{{ trans('mail.contract_renewal_alert_footer') }}
@endcomponent
