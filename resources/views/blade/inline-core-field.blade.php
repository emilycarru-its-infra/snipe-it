@props([
    'asset',
    'column',
    'element' => 'text',
    'copy_what' => null,
    'editable' => true,
    'options' => [],
])

{{--
    Inline single-field editor for a native asset column. Layout: value [copy][pencil].
    Both controls sit muted/gray to the right of the value: copy (double-square icon)
    copies via clipboard.js; the pencil swaps the value for an input posting to
    hardware.corefield.update. Set :editable="false" for high-stakes fields (asset tag,
    serial) to drop the pencil while keeping copy. Progressive: with no JS the form stays
    hidden and the full edit form still works. The column must be in
    Asset::inlineEditableCoreFields().
--}}
@php
    $canEdit  = $editable && auth()->user()?->can('update', $asset);
    $editId   = 'inline-core-'.$asset->id.'-'.$column;
    $raw      = $asset->{$column};
    $hasValue = ($raw !== null && $raw !== '');
@endphp

<span class="js-inline-display inline-core-field" id="{{ $editId }}-display">
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
    @if ($canEdit)
        <a href="#" class="js-inline-edit-toggle hidden-print inline-core-pencil" data-target="{{ $editId }}" data-tooltip="true" data-placement="top" title="{{ trans('general.edit') }}">
            <i class="fas fa-pencil-alt" aria-hidden="true"></i>
        </a>
    @endif
</span>
@if ($canEdit)
    <form class="js-inline-edit-form form-inline hidden-print" id="{{ $editId }}-form" method="POST" action="{{ route('hardware.corefield.update', $asset->id) }}" style="display:none;">
        {{ csrf_field() }}
        @method('PATCH')
        <input type="hidden" name="field" value="{{ $column }}">
        @if ($element === 'textarea')
            <textarea name="value" class="form-control input-sm" rows="2" style="min-width: 220px;">{{ $raw }}</textarea>
        @elseif ($element === 'select')
            <select name="value" class="form-control input-sm" style="min-width: 220px;">
                <option value="">—</option>
                @foreach ($options as $optId => $optLabel)
                    <option value="{{ $optId }}" {{ (string) $raw === (string) $optId ? 'selected' : '' }}>{{ $optLabel }}</option>
                @endforeach
            </select>
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
            /* Both controls muted/gray, to the RIGHT of the value. The
               !important + nested selector beat AdminLTE's `.box a` link
               colour, which would otherwise tint the pencil blue. */
            .inline-core-field .inline-core-pencil,
            .inline-core-field .inline-core-pencil i { color: #bbb !important; }
            .inline-core-pencil { margin-left: 8px; font-size: 13px; vertical-align: baseline; }
            .inline-core-field .inline-core-pencil:hover,
            .inline-core-field .inline-core-pencil:focus,
            .inline-core-field .inline-core-pencil:hover i { color: #777 !important; }
            .inline-core-copy { color: #bbb; cursor: pointer; margin-left: 8px; font-size: 14px; vertical-align: baseline; }
            .inline-core-copy:hover { color: #777; }

            /* Edit/copy affordances stay hidden until the row is hovered — keeps
               the dense field lists clean. Revealed on hover of the containing
               card row, sidebar list item, or identity-header field. */
            .inline-core-pencil, .inline-core-copy { opacity: 0; transition: opacity .12s ease; }
            .asset-card-row:hover .inline-core-pencil,
            .asset-card-row:hover .inline-core-copy,
            .list-group-item:hover .inline-core-pencil,
            .list-group-item:hover .inline-core-copy,
            .asset-identity-field:hover .inline-core-pencil,
            .asset-identity-field:hover .inline-core-copy { opacity: .6; }
            .asset-card-row:hover .inline-core-pencil:hover,
            .asset-card-row:hover .inline-core-copy:hover,
            .list-group-item:hover .inline-core-pencil:hover,
            .list-group-item:hover .inline-core-copy:hover,
            .asset-identity-field:hover .inline-core-pencil:hover,
            .asset-identity-field:hover .inline-core-copy:hover { opacity: 1; }
            /* Keep them visible while actively editing (form open). */
            .js-inline-edit-form:not([style*="display:none"]) ~ * .inline-core-pencil { opacity: 1; }

            /* the customfield info-element renders its own leading copy icon; we
               supply a single right-aligned one instead, so hide the embedded one. */
            .inline-core-value .js-copy-link { display: none !important; }
        </style>
    @endpush
@endonce
