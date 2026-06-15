@props([
    'asset',
    'column',
    'element' => 'text',
    'copy_what' => null,
])

{{--
    Inline single-field editor for a native asset column. Renders the value
    (optionally with a copy button and custom display markup via the slot) plus
    a pencil that swaps it for an input posting to hardware.corefield.update.
    Progressive: with no JS the form stays hidden and the full edit form still
    works; the column must be in Asset::inlineEditableCoreFields().
--}}
@php
    $canEdit = auth()->user()?->can('update', $asset);
    $editId  = 'inline-core-'.$asset->id.'-'.$column;
    $raw     = $asset->{$column};
    $hasValue = ($raw !== null && $raw !== '');
@endphp

<span class="js-inline-display" id="{{ $editId }}-display">
    @if ($hasValue)
        @if ($copy_what)
            <x-copy-to-clipboard copy_what="{{ $copy_what }}">{{ $slot->isEmpty() ? $raw : $slot }}</x-copy-to-clipboard>
        @else
            {{ $slot->isEmpty() ? $raw : $slot }}
        @endif
    @else
        <span class="text-muted"><em>{{ trans('general.no_value') }}</em></span>
    @endif
    @if ($canEdit)
        <a href="#" class="js-inline-edit-toggle hidden-print text-muted" data-target="{{ $editId }}" style="margin-left: 6px; font-size: 14px;" data-tooltip="true" title="{{ trans('general.edit') }}">
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
        @else
            <input type="text" name="value" class="form-control input-sm" style="min-width: 220px;" value="{{ $raw }}">
        @endif
        <button type="submit" class="btn btn-xs btn-primary"><i class="fas fa-check" aria-hidden="true"></i> {{ trans('general.save') }}</button>
        <a href="#" class="btn btn-xs btn-default js-inline-edit-cancel" data-target="{{ $editId }}">{{ trans('general.cancel') }}</a>
    </form>
@endif

@once
    @push('js')
        <script nonce="{{ csrf_token() }}">
            $(function () {
                function showForm(target) {
                    $('#' + target + '-display').hide();
                    $('#' + target + '-form').show().find('input[name="value"], textarea[name="value"]').first().focus();
                }
                function hideForm(target) {
                    $('#' + target + '-form').hide();
                    $('#' + target + '-display').show();
                }
                $(document).on('click', '.js-inline-edit-toggle', function (e) {
                    e.preventDefault();
                    showForm($(this).data('target'));
                });
                $(document).on('click', '.js-inline-edit-cancel', function (e) {
                    e.preventDefault();
                    hideForm($(this).data('target'));
                });
            });
        </script>
    @endpush
@endonce
