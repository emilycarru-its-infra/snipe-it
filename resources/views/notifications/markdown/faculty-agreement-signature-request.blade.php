@php
    $typeLabel = match ($agreement->agreement_type) {
        'upgrade'            => trans('admin/faculty-agreements/general.type_upgrade'),
        'lease_end_purchase' => trans('admin/faculty-agreements/general.type_buyout'),
        default              => trans('admin/faculty-agreements/general.type_pickup'),
    };
@endphp
@component('mail::message')
# {{ trans('mail.faculty_signature_request_greeting', ['name' => $agreement->user->first_name ?? '']) }}

{{ trans('mail.faculty_signature_request_body', ['type' => $typeLabel, 'asset_tag' => $variables['asset_tag'] ?? '—', 'model' => $variables['model'] ?? '—']) }}

<x-mail::table>

| | |
| :- | :- |
| **{{ trans('admin/faculty-agreements/general.asset') }}** | {{ $variables['asset_tag'] ?? '—' }} — {{ $variables['model'] ?? '—' }} |
| **{{ trans('admin/faculty-agreements/general.serial') }}** | {{ $variables['serial'] ?? '—' }} |
@if (! empty($variables['base_price']))
| **{{ trans('admin/faculty-agreements/general.base_price') }}** | {{ $variables['base_price'] }} |
@endif
@if (! empty($variables['upgrade_amount']))
| **{{ trans('admin/faculty-agreements/general.upgrade_amount') }}** | {{ $variables['upgrade_amount'] }} |
@endif
@if (! empty($variables['buyout_cost']))
| **{{ trans('admin/faculty-agreements/general.buyout_cost') }}** | {{ $variables['buyout_cost'] }} |
@endif

</x-mail::table>

<x-mail::button :url="$agreement->checkout_acceptance_id ? route('account.accept.item', $agreement->checkout_acceptance_id) : route('account.accept')">
{{ trans('mail.faculty_signature_request_cta') }}
</x-mail::button>

{{ trans('mail.faculty_signature_request_attachment_note') }}

{{ trans('mail.faculty_signature_request_footer') }}
@endcomponent
