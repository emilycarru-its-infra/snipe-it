@props([
    'asset',
    'field',
])

{{--
    Inline editor for one custom field on the grouped asset detail boxes. Same
    [pencil] value [copy] layout as <x-inline-core-field>: pencil (left) swaps
    the value for an input posting to hardware.field.update; copy (right, muted)
    copies the displayed value. Only free-text (text/textarea) fields are
    editable inline — others render display + copy only. Encrypted fields are
    editable only with the encrypted-fields gate and are decrypted into the
    input. Shared pencil/copy CSS comes from <x-inline-core-field>; the
    toggle/cancel JS lives once at the bottom of hardware/view.blade.php.
--}}
@php
    $column      = $field->db_column_name();
    $isEncrypted = $field->field_encrypted == '1';
    $canEdit     = auth()->user()?->can('update', $asset)
        && in_array($field->element, ['text', 'textarea'], true)
        && ! ($isEncrypted && Gate::denies('assets.view.encrypted_custom_fields'));
    $stored   = $asset->{$column};
    $hasValue = (! empty($stored) || $stored === '0');
    $editId   = 'inline-custom-'.$asset->id.'-'.$field->id;
    $copyWhat = 'cf-'.$asset->id.'-'.$field->id;

    $rawVal = $stored;
    if ($isEncrypted && $canEdit && $field->isFieldDecryptable($rawVal)) {
        $rawVal = \App\Helpers\Helper::gracefulDecrypt($field, $rawVal);
    }
@endphp

<span class="js-inline-display inline-core-field" id="{{ $editId }}-display">
    @if ($canEdit)
        <a href="#" class="js-inline-edit-toggle hidden-print inline-core-pencil" data-target="{{ $editId }}" data-tooltip="true" data-placement="top" title="{{ trans('general.edit') }}">
            <i class="fas fa-pencil-alt" aria-hidden="true"></i>
        </a>
    @endif
    <span class="inline-core-value js-copy-{{ $copyWhat }}">
        @if ($hasValue)
            <x-info-element.customfield :item="$asset" :field="$field"/>
        @else
            <span class="text-muted"><em>{{ trans('general.no_value') }}</em></span>
        @endif
    </span>
    @if ($hasValue)
        <i class="js-copy-link far fa-copy hidden-print inline-core-copy" data-clipboard-target=".js-copy-{{ $copyWhat }}" data-tooltip="true" data-placement="top" title="{{ trans('general.copy_to_clipboard') }}" aria-hidden="true"></i>
    @endif
</span>
@if ($canEdit)
    <form class="js-inline-edit-form form-inline hidden-print" id="{{ $editId }}-form" method="POST" action="{{ route('hardware.field.update', $asset->id) }}" style="display:none;">
        {{ csrf_field() }}
        @method('PATCH')
        <input type="hidden" name="field" value="{{ $field->db_column }}">
        @if ($field->element === 'textarea')
            <textarea name="value" class="form-control input-sm" rows="2" style="min-width: 220px;">{{ $rawVal }}</textarea>
        @else
            <input type="text" name="value" class="form-control input-sm" style="min-width: 220px;" value="{{ $rawVal }}">
        @endif
        <button type="submit" class="btn btn-xs btn-primary"><i class="fas fa-check" aria-hidden="true"></i> {{ trans('general.save') }}</button>
        <a href="#" class="btn btn-xs btn-default js-inline-edit-cancel" data-target="{{ $editId }}">{{ trans('general.cancel') }}</a>
    </form>
@endif
