{{-- Wraps an admin-authored email body (already rendered from Handlebars to
     Markdown by App\Mail\EmailTemplateRenderer) in the standard branded mail
     chrome, so a custom body still gets the header/footer. The Markdown mail
     pipeline converts $body's Markdown to themed HTML. --}}
@component('mail::message')
{!! $body !!}
@endcomponent
