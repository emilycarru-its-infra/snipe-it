{{--
    The email masthead, set in type rather than as a logo image. Mail
    clients drop an image's CSS size when someone replies or forwards, so a
    logo comes back at its full pixel size in every reply thread; text keeps
    its size, never needs "download pictures", and reads in plain-text views.
    MAIL_WORDMARK and MAIL_WORDMARK_TAGLINE set the lines; the site name is
    the fallback.
--}}
@php
    $wordmark = config('mail.wordmark.name') ?: ($snipeSettings->site_name ?? config('app.name'));
    $tagline = config('mail.wordmark.tagline');
@endphp
<span style="display: block; font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 20px; line-height: 24px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #2b2d30; text-shadow: none;">{{ $wordmark }}</span>
@if ($tagline)
<span style="display: block; font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 12px; line-height: 18px; font-weight: normal; letter-spacing: 2px; text-transform: uppercase; color: #74787e; text-shadow: none;">{{ $tagline }}</span>
@endif
