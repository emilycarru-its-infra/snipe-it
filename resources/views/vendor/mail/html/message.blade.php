@component('mail::layout')
{{-- Header --}}
@slot('header')

{{-- Check that the $snipeSettings variable is set, images are set to be shown, and setup is complete --}}
<style>

    th, td {
        vertical-align: top;
    }
    hr {
        display: block;
        height: 1px;
        border: 0;
        border-top: 1px solid #edeff2;
        margin: 1em 0;
        padding: 0;
    }
</style>

@if (isset($snipeSettings) && ($snipeSettings::setupCompleted()))

    @component('mail::header', ['url' => $snipeSettings->show_url_in_emails == '1' ? config('app.url') : ''])
        @include('vendor.mail.partials.wordmark')
    @endcomponent
@endif
@endslot

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
@slot('subcopy')
@component('mail::subcopy')
{{ $subcopy }}
@endcomponent
@endslot
@endisset

{{-- Footer --}}
@slot('footer')
@component('mail::footer')
© {{ date('Y') }} ECU ITS Assets Management

@if ($snipeSettings->privacy_policy_link!='')
<a href="{{ $snipeSettings->privacy_policy_link }}">{{ trans('admin/settings/general.privacy_policy') }}</a>
@endif

@endcomponent
@endslot
@endcomponent
