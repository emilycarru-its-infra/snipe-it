@component('mail::message')
# {{ trans('mail.lease_pickup_request_heading') }}

{{ trans('mail.lease_pickup_request_intro', ['lessor' => $lessor->name ?? trans('general.lessor'), 'count' => $assets->count()]) }}

@if (filled($pickup->preferred_dates))
**{{ trans('mail.lease_pickup_request_preferred') }}** {{ $pickup->preferred_dates }}
@endif

<x-mail::table>

| {{ trans('mail.lease_pickup_request_col_schedule') }} | {{ trans('mail.lease_pickup_request_col_devices') }} |
| :- | -: |
@foreach ($schedules as $schedule => $count)
| {{ $schedule }} | {{ $count }} |
@endforeach

</x-mail::table>

<x-mail::table>

| {{ trans('general.asset_tag') }} | {{ trans('admin/hardware/form.serial') }} | {{ trans('general.asset_model') }} | {{ trans('mail.lease_pickup_request_col_schedule') }} | {{ trans('general.lease_end_date') }} |
| :- | :- | :- | :- | :- |
@foreach ($assets as $asset)
| {{ $asset->asset_tag }} | {{ $asset->serial ?: '—' }} | {{ trim(($asset->model?->manufacturer?->name ?? '').' '.($asset->model?->name ?? '')) ?: '—' }} | {{ $asset->lease_contract_id ?: '—' }} | {{ $asset->lease_end_date ?: '—' }} |
@endforeach

</x-mail::table>

{{ trans('mail.lease_pickup_request_ready') }}

@if (filled($siteDetails))
**{{ trans('mail.lease_pickup_request_site') }}**

{!! nl2br(e($siteDetails)) !!}
@endif

@if (filled($pickup->notes))
**{{ trans('mail.lease_pickup_request_notes') }}** {{ $pickup->notes }}
@endif

{{ trans('mail.lease_pickup_request_body') }}

@isset($requester)
{{ trans('mail.lease_pickup_request_signoff', ['name' => $requester->full_name ?: $requester->email]) }}
@endisset
@endcomponent
