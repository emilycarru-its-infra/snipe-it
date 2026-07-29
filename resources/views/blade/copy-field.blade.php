@props([
    'value' => null,
    'display' => null,
])

{{-- A value with a copy button that appears on hover. Behaviour and styling
     come from partials/copy-fields, which the page must include once.

     `display` lets what is shown differ from what is copied — currency reads
     better as "$1,234.56" but pastes into a finance system as "1234.56". --}}
@php
    $copyValue = trim((string) ($value ?? ''));
@endphp

@if ($copyValue === '')
    <span class="cp-empty">{{ trans('general.na') }}</span>
@else
    <span {{ $attributes->merge(['class' => 'cp-field']) }} data-copy="{{ $copyValue }}">{{ $display ?? $copyValue }}</span>
@endif
