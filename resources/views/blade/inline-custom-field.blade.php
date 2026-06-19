@props([
    'asset',
    'field',
])

{{--
    Inline editor for one custom field on the grouped asset detail boxes. Same
    value [copy][pencil] layout as <x-inline-core-field>: both controls sit
    muted/gray to the right of the value. The pencil swaps the value for the
    right editor — a text input / textarea for free-text fields, a <select> for
    listbox/radio, checkboxes for checkbox — posting to hardware.field.update.
    Copy (muted, double-square) reuses the value's existing hidden copy target
    from <x-info-element.customfield>; that
    component's own leading copy icon is hidden via CSS so there's just one.
    Encrypted fields are editable only with the encrypted-fields gate and are
    decrypted into the input. Shared pencil/copy CSS + the toggle/cancel JS come
    from <x-inline-core-field> / the bottom of hardware/view.blade.php.
--}}
@php
    $column      = $field->db_column_name();
    $isEncrypted = $field->field_encrypted == '1';
    $editable    = in_array($field->element, ['text', 'textarea', 'listbox', 'radio', 'checkbox'], true);
    $canEdit     = $editable
        && auth()->user()?->can('update', $asset)
        && ! ($isEncrypted && Gate::denies('assets.view.encrypted_custom_fields'));
    $stored   = $asset->{$column};
    $hasValue = (! empty($stored) || $stored === '0');
    $editId   = 'inline-custom-'.$asset->id.'-'.$field->id;

    $rawVal = $stored;
    if ($isEncrypted && $canEdit && $field->isFieldDecryptable($rawVal)) {
        $rawVal = \App\Helpers\Helper::gracefulDecrypt($field, $rawVal);
    }
    $checkedVals = array_map('trim', explode(',', (string) $rawVal));
@endphp

<span class="js-inline-display inline-core-field" id="{{ $editId }}-display">
    <span class="inline-core-value">
        @if ($hasValue)
            <x-info-element.customfield :item="$asset" :field="$field"/>
        @else
            <span class="text-muted"><em>{{ trans('general.no_value') }}</em></span>
        @endif
    </span>
    @if ($hasValue)
        <i class="js-copy-link far fa-copy hidden-print inline-core-copy" data-clipboard-target=".js-copy-{{ $field->id }}" data-tooltip="true" data-placement="top" title="{{ trans('general.copy_to_clipboard') }}" aria-hidden="true"></i>
    @endif
    @if ($canEdit)
        <a href="#" class="js-inline-edit-toggle hidden-print inline-core-pencil" data-target="{{ $editId }}" data-tooltip="true" data-placement="top" title="{{ trans('general.edit') }}">
            <i class="fas fa-pencil-alt" aria-hidden="true"></i>
        </a>
    @endif
</span>
@if ($canEdit)
    <form class="js-inline-edit-form form-inline hidden-print" id="{{ $editId }}-form" method="POST" action="{{ route('hardware.field.update', $asset->id) }}" style="display:none;">
        {{ csrf_field() }}
        @method('PATCH')
        <input type="hidden" name="field" value="{{ $field->db_column }}">
        @switch ($field->element)
            @case ('textarea')
                <textarea name="value" class="form-control input-sm" rows="2" style="min-width: 220px;">{{ $rawVal }}</textarea>
                @break
            @case ('listbox')
            @case ('radio')
                <select name="value" class="form-control input-sm" style="min-width: 220px;">
                    @foreach ($field->formatFieldValuesAsArray() as $optVal => $optLabel)
                        <option value="{{ $optVal }}" {{ (string) $rawVal === (string) $optVal ? 'selected' : '' }}>{{ $optLabel }}</option>
                    @endforeach
                </select>
                @break
            @case ('checkbox')
                @foreach ($field->formatFieldValuesAsArray() as $optVal => $optLabel)
                    <label class="checkbox-inline">
                        <input type="checkbox" name="value[]" value="{{ $optVal }}" {{ in_array($optVal, $checkedVals, true) ? 'checked' : '' }}> {{ $optLabel }}
                    </label>
                @endforeach
                @break
            @default
                <input type="text" name="value" class="form-control input-sm" style="min-width: 220px;" value="{{ $rawVal }}">
        @endswitch
        <button type="submit" class="btn btn-xs btn-primary"><i class="fas fa-check" aria-hidden="true"></i> {{ trans('general.save') }}</button>
        <a href="#" class="btn btn-xs btn-default js-inline-edit-cancel" data-target="{{ $editId }}">{{ trans('general.cancel') }}</a>
    </form>
@endif
