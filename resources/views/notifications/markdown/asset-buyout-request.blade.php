@component('mail::message')
# {{ trans('mail.asset_buyout_request_heading') }}

{{ trans('mail.asset_buyout_request_intro', ['lessor' => $lessor->name ?? trans('general.lessor')]) }}

<x-mail::table>

| | |
| :- | :- |
| {{ trans('general.asset_tag') }} | {{ $asset->asset_tag }} |
| {{ trans('admin/hardware/form.serial') }} | {{ $asset->serial ?: '—' }} |
| {{ trans('general.asset_model') }} | {{ $asset->model->name ?? '—' }} |
| {{ trans('admin/contracts/general.contract_number') }} | {{ $lease['contract_id'] ?: '—' }} |
| {{ trans('general.lease_end_date') }} | {{ $lease['end_date'] ?: '—' }} |
| {{ trans('general.buyout_cost') }} | {{ $lease['buyout_cost'] ?: '—' }} |

</x-mail::table>

{{ trans('mail.asset_buyout_request_body') }}

@isset($requester)
{{ trans('mail.asset_buyout_request_signoff', ['name' => $requester->full_name ?: $requester->email]) }}
@endisset
@endcomponent
