{{-- Inline single-field editor for a wave column, mirroring the asset page's
     pencil pattern: value [pencil on hover] swaps for an input + save/cancel
     posting one field to deployment-waves.update. Progressive: with no JS the
     form stays hidden and nothing on the page breaks; the field simply reads.

     @include params:
       wave           the DeploymentWave
       field          column name — must be in DeploymentsController::INLINE_FIELDS
       element        'text' | 'date' | 'select' | 'user' | 'textarea' | 'color'
       options        [id => label] for 'select'
       display        pre-rendered display value (falls back to the raw column)
       selectedLabel  current label for the 'user' select2 seed option --}}
@php
    $element = $element ?? 'text';
    $options = $options ?? [];
    $editId = 'wave-inline-'.$wave->id.'-'.$field;
    $raw = $wave->{$field};
    if ($raw instanceof \Illuminate\Support\Carbon) {
        $raw = $raw->toDateString();
    }
    $shown = $display ?? $raw;
    $canEdit = auth()->user()?->can('deployments.edit');
@endphp

<span class="js-inline-display wave-inline-field" id="{{ $editId }}-display">
    <span class="wave-inline-value">{{ ($shown !== null && $shown !== '') ? $shown : '—' }}</span>
    @if ($canEdit)
        <a href="#" class="js-inline-edit-toggle wave-inline-pencil hidden-print" data-target="{{ $editId }}" data-tooltip="true" data-placement="top" title="{{ trans('general.edit') }}">
            <i class="fas fa-pencil-alt" aria-hidden="true"></i>
        </a>
    @endif
</span>
@if ($canEdit)
    <form class="js-inline-edit-form form-inline hidden-print" id="{{ $editId }}-form" method="POST" action="{{ route('deployment-waves.update', $wave) }}" style="display:none;">
        {{ csrf_field() }}
        @method('PATCH')
        <input type="hidden" name="field" value="{{ $field }}">
        @if ($element === 'textarea')
            <textarea name="value" class="form-control input-sm" rows="2" style="min-width:260px;">{{ $raw }}</textarea>
        @elseif ($element === 'date')
            <input type="date" name="value" class="form-control input-sm" style="min-width:150px;" value="{{ $raw }}">
        @elseif ($element === 'color')
            <input type="color" name="value" value="{{ $raw ?: '#2980b9' }}" style="height:30px; width:60px; vertical-align:middle;">
        @elseif ($element === 'select')
            <select name="value" class="form-control input-sm" style="min-width:200px;">
                <option value="">—</option>
                @foreach ($options as $optId => $optLabel)
                    <option value="{{ $optId }}" {{ (string) $raw === (string) $optId ? 'selected' : '' }}>{{ $optLabel }}</option>
                @endforeach
            </select>
        @elseif ($element === 'user')
            <select name="value" class="js-data-ajax" data-endpoint="users" data-placeholder="{{ trans('general.select_user') }}" style="min-width:220px;">
                @if ($raw)
                    <option value="{{ $raw }}" selected>{{ $selectedLabel ?? $raw }}</option>
                @endif
            </select>
        @else
            <input type="text" name="value" class="form-control input-sm" style="min-width:200px;" value="{{ $raw }}">
        @endif
        <button type="submit" class="btn btn-xs btn-primary"><i class="fas fa-check" aria-hidden="true"></i> {{ trans('general.save') }}</button>
        <a href="#" class="btn btn-xs btn-default js-inline-edit-cancel" data-target="{{ $editId }}">{{ trans('button.cancel') }}</a>
    </form>
@endif
