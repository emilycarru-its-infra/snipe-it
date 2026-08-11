@component('mail::message')
<div style="text-align: left;">

{!! \Illuminate\Support\Str::markdown($body) !!}

</div>
@endcomponent
