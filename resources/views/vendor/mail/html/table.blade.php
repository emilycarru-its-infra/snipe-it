@props(['keyValue' => false])
<div class="table{{ $keyValue ? ' table-key-value' : '' }}">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</div>
