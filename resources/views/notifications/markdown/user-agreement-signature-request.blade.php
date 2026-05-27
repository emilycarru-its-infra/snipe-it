@php
    $typeLabel = match ($agreement->agreement_type) {
        'upgrade'            => trans('admin/user-agreements/general.type_upgrade'),
        'lease_end_purchase' => trans('admin/user-agreements/general.type_buyout'),
        default              => trans('admin/user-agreements/general.type_pickup'),
    };
@endphp
@component('mail::message')
# {{ trans('mail.user_agreement_signature_request_greeting', ['name' => $agreement->user->first_name ?? '']) }}

{{ trans('mail.user_agreement_signature_request_body', ['type' => $typeLabel, 'asset_tag' => $variables['asset_tag'] ?? '—', 'model' => $variables['model'] ?? '—']) }}

<x-mail::table>

| | |
| :- | :- |
| **{{ trans('admin/user-agreements/general.asset') }}** | {{ $variables['asset_tag'] ?? '—' }} — {{ $variables['model'] ?? '—' }} |
| **{{ trans('admin/user-agreements/general.serial') }}** | {{ $variables['serial'] ?? '—' }} |
@if (! empty($variables['base_price']))
| **{{ trans('admin/user-agreements/general.base_price') }}** | {{ $variables['base_price'] }} |
@endif
@if (! empty($variables['upgrade_amount']))
| **{{ trans('admin/user-agreements/general.upgrade_amount') }}** | {{ $variables['upgrade_amount'] }} |
@endif
@if (! empty($variables['buyout_cost']))
| **{{ trans('admin/user-agreements/general.buyout_cost') }}** | {{ $variables['buyout_cost'] }} |
@endif

</x-mail::table>

<x-mail::button :url="$agreement->checkout_acceptance_id ? route('account.accept.item', $agreement->checkout_acceptance_id) : route('account.accept')">
{{ trans('mail.user_agreement_signature_request_cta') }}
</x-mail::button>

{{ trans('mail.user_agreement_signature_request_attachment_note') }}

{{ trans('mail.user_agreement_signature_request_footer') }}
@endcomponent
