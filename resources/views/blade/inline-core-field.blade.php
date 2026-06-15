@props([
    'asset',
    'column',
    'element' => 'text',
    'copy_what' => null,
    'editable' => true,
])

{{--
    Inline single-field editor for a native asset column. Layout: [pencil] value [copy].
    The pencil (left) swaps the value for an input posting to hardware.corefield.update;
    the copy button (right, muted, double-square icon) copies the value via clipboard.js.
    Set :editable="false" for high-stakes fields (asset tag, serial) to drop the pencil
    while keeping copy. Progressive: with no JS the form stays hidden and the full edit
    form still works. The column must be in Asset::inlineEditableCoreFields().
--}}
@php
    $canEdit  = $editable && auth()->user()?->can('update', $asset);
    $editId   = 'inline-core-'.$asset->id.'-'.$column;
    $raw      = $asset->{$column};
    $hasValue = ($raw !== null && $raw !== '');
@endphp

<span class="js-inline-display inline-core-field" id="{{ $editId }}-display">
    @if ($canEdit)
        <a href="#" class="js-inline-edit-toggle hidden-print inline-core-pencil" data-target="{{ $editId }}" data-tooltip="true" data-placement="top" title="{{ trans('general.edit') }}">
            <i class="fas fa-pencil-alt" aria-hidden="true"></i>
        </a>
    @endif
    <span class="inline-core-value @if ($copy_what) js-copy-{{ $copy_what }} @endif">
        @if ($hasValue)
            {{ $slot->isEmpty() ? $raw : $slot }}
        @else
            <span class="text-muted"><em>{{ trans('general.no_value') }}</em></span>
        @endif
    </span>
    @if ($hasValue && $copy_what)
        <i class="js-copy-link far fa-copy hidden-print inline-core-copy" data-clipboard-target=".js-copy-{{ $copy_what }}" data-tooltip="true" data-placement="top" title="{{ trans('general.copy_to_clipboard') }}" aria-hidden="true"></i>
    @endif
</span>
@if ($canEdit)
    <form class="js-inline-edit-form form-inline hidden-print" id="{{ $editId }}-form" method="POST" action="{{ route('hardware.corefield.update', $asset->id) }}" style="display:none;">
        {{ csrf_field() }}
        @method('PATCH')
        <input type="hidden" name="field" value="{{ $column }}">
        @if ($element === 'textarea')
            <textarea name="value" class="form-control input-sm" rows="2" style="min-width: 220px;">{{ $raw }}</textarea>
        @else
            <input type="text" name="value" class="form-control input-sm" style="min-width: 220px;" value="{{ $raw }}">
        @endif
        <button type="submit" class="btn btn-xs btn-primary"><i class="fas fa-check" aria-hidden="true"></i> {{ trans('general.save') }}</button>
        <a href="#" class="btn btn-xs btn-default js-inline-edit-cancel" data-target="{{ $editId }}">{{ trans('general.cancel') }}</a>
    </form>
@endif

@once
    {{-- Shared styling for the inline pencil/copy controls (also used by
         <x-inline-custom-field>). The toggle/cancel JS lives once at the bottom
         of hardware/view.blade.php. --}}
    @push('css')
        <style>
            .inline-core-pencil { color: #bbb; margin-right: 7px; font-size: 13px; }
            .inline-core-pencil:hover, .inline-core-pencil:focus { color: #777; }
            .inline-core-copy { color: #bbb; opacity: .7; cursor: pointer; margin-left: 8px; font-size: 14px; vertical-align: baseline; }
            .inline-core-copy:hover { color: #777; opacity: 1; }
            /* <x-info-element.customfield> renders its own leading copy icon; we
               supply a single right-aligned one instead, so hide the embedded one. */
            .inline-core-value .js-copy-link { display: none !important; }
        </style>
    @endpush
@endonce
