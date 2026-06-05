@component('mail::message')
# {{ trans('mail.hello') }},

@if ($expected_checkin_date > now())
{{ trans('mail.Expected_Checkin_Date', ['date' => $date]) }}
@else
{{ trans('mail.Expected_Checkin_Date_Past', ['date' => $date]) }}
@endif

<x-mail::table>
|        |        |
| ------------- | ------------- |
@if ((isset($asset)) && ($asset!=''))
| **{{ trans('mail.asset_name') }}** | {{ $asset }} |
@endif
| **{{ trans('mail.asset_tag') }}** | {{ $asset_tag }} |
@if (isset($serial))
| **{{ trans('mail.serial') }}** | {{ $serial }} |
@endif
</x-mail::table>

**[{{ trans('mail.your_assets') }}]({{ route('view-assets') }})**

{{ trans('mail.best_regards') }}

ECU ITS Assets Management

@endcomponent
