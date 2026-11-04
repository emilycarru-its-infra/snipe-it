@php
    $typeLabel = match ($agreement->agreement_type) {
        'upgrade'  => trans('admin/user-agreements/general.type_upgrade'),
        'purchase' => trans('admin/user-agreements/general.type_purchase'),
        default    => trans('admin/user-agreements/general.type_pickup'),
    };
@endphp
@component('mail::message')
# {{ trans('mail.user_agreement_signature_request_greeting', ['name' => $agreement->user?->first_name ?? '']) }}

{{ trans('mail.user_agreement_signature_request_body', ['type' => $typeLabel, 'asset_tag' => $variables['asset_tag'] ?? '—', 'model' => $variables['model'] ?? '—']) }}

<x-mail::table>

| | |
| :- | :- |
| **{{ trans('admin/user-agreements/general.asset') }}** | {{ $variables['asset_tag'] ?? '—' }} — {{ $variables['model'] ?? '—' }} |
| **{{ trans('admin/user-agreements/general.serial') }}** | {{ $variables['serial'] ?? '—' }} |
@if ($agreement->base_program_price !== null)
| **{{ trans('admin/user-agreements/general.base_price') }}** | {{ $variables['base_price'] }} |
@endif
@if ($agreement->agreement_type === 'upgrade' && (float) $agreement->top_up_amount > 0)
| **{{ trans('admin/user-agreements/general.upgrade_amount') }}** | {{ $variables['upgrade_amount'] }} |
@endif
@if ($agreement->agreement_type === 'purchase' && $agreement->buyout_cost !== null)
| **{{ trans('admin/user-agreements/general.buyout_cost') }}** | {{ $variables['buyout_cost'] }} |
@endif

</x-mail::table>

<x-mail::button :url="$agreement->checkout_acceptance_id ? route('account.accept.item', $agreement->checkout_acceptance_id) : route('account.accept')">
{{ trans('mail.user_agreement_signature_request_cta') }}
</x-mail::button>

{{ trans('mail.user_agreement_signature_request_attachment_note') }}

{{ trans('mail.user_agreement_signature_request_footer') }}
@endcomponent
