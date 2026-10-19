@component('mail::message')
# {{ trans('mail.lease_pickup_receiving_heading') }}

{{ trans('mail.lease_pickup_receiving_intro', ['lessor' => $lessor->name ?? trans('general.lessor'), 'count' => $assets->count()]) }}

@if ($updated)
{{ trans('mail.lease_pickup_receiving_updated') }}
@endif

**{{ trans('mail.lease_pickup_receiving_when') }}** {{ $when }}

@if (filled($pickup->load_number))
**{{ trans('mail.lease_pickup_receiving_load') }}** {{ $pickup->load_number }} — {{ trans('mail.lease_pickup_receiving_load_hint') }}
@endif

@if (count($locations) && array_keys($locations) !== ['—'])
**{{ trans('mail.lease_pickup_receiving_where') }}** {{ collect($locations)->map(fn ($count, $room) => $room.' ('.$count.')')->implode(', ') }}
@endif

<x-mail::table>

| {{ trans('general.asset_tag') }} | {{ trans('admin/hardware/form.serial') }} | {{ trans('general.asset_model') }} | {{ trans('mail.lease_pickup_request_col_schedule') }} |
| :- | :- | :- | :- |
@foreach ($assets as $asset)
| {{ $asset->asset_tag }} | {{ $asset->serial ?: '—' }} | {{ trim(($asset->model?->manufacturer?->name ?? '').' '.($asset->model?->name ?? '')) ?: '—' }} | {{ $asset->lease_contract_id ?: '—' }} |
@endforeach

</x-mail::table>

{{ trans('mail.lease_pickup_receiving_body') }}

@isset($requester)
{{ trans('mail.lease_pickup_receiving_signoff', ['name' => $requester->full_name ?: $requester->email]) }}
@endisset
@endcomponent
