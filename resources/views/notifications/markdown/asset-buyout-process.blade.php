@component('mail::message')
# {{ trans('mail.asset_buyout_process_heading') }}

{{ trans('mail.asset_buyout_process_intro', ['lessor' => $lessor->name ?? trans('general.lessor')]) }}

<x-mail::table>

| | |
| :- | :- |
| {{ trans('general.asset_tag') }} | {{ $asset->asset_tag }} |
| {{ trans('admin/hardware/form.serial') }} | {{ $asset->serial ?: '—' }} |
| {{ trans('general.asset_model') }} | {{ $asset->model->name ?? '—' }} |
| {{ trans('admin/contracts/general.contract_number') }} | {{ $lease['contract_id'] ?: '—' }} |
| {{ trans('general.lease_end_date') }} | {{ $lease['end_date'] ?: '—' }} |
| {{ trans('general.buyout_cost') }} | {{ $recorded_cost }} |
| {{ trans('mail.asset_buyout_process_buyer') }} | {{ $buyer?->full_name }} |
| {{ trans('mail.asset_buyout_process_share') }} | {{ $buyer_cost }} |

</x-mail::table>

@if ($accepted)
{{ trans('mail.asset_buyout_process_accepted') }}
@else
{{ trans('mail.asset_buyout_process_accept', ['buyer' => $buyer?->full_name, 'amount' => $buyer_cost]) }}

@if ($acceptance_url)
@component('mail::button', ['url' => $acceptance_url])
{{ trans('mail.asset_buyout_process_cta') }}
@endcomponent
@endif
@endif

{{ trans('mail.asset_buyout_process_invoice', ['lessor' => $lessor->name ?? trans('general.lessor')]) }}

@isset($requester)
{{ trans('mail.asset_buyout_request_signoff', ['name' => $requester->full_name ?: $requester->email]) }}
@endisset
@endcomponent
