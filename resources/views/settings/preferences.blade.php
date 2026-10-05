@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/settings/preferences.title') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('settings.index') }}" class="btn btn-primary"> {{ trans('general.back') }}</a>
@stop

{{-- Page content --}}
@section('content')

    @php
        $display = function ($value) {
            if (is_bool($value)) {
                return $value ? trans('general.yes') : trans('general.no');
            }
            if (is_array($value)) {
                return $value ? implode(', ', $value) : trans('admin/settings/preferences.none');
            }

            return (string) $value;
        };
        $oldPrefs = (array) old('prefs', []);
        $months = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Carbon\Carbon::create(2000, $m, 1)->translatedFormat('F')]);
    @endphp

    <form method="POST" action="{{ route('settings.preferences.save') }}" autocomplete="off" class="form-horizontal" role="form" id="create-form">
    {{ csrf_field() }}

    <div class="row">
        <div class="col-sm-10 col-sm-offset-1 col-md-8 col-md-offset-2">

            <div class="panel box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        <x-icon type="settings"/> {{ trans('admin/settings/preferences.title') }}
                    </h2>
                </div>
                <div class="box-body">
                    <div class="col-md-12">

                        <div class="alert alert-info">{{ trans('admin/settings/preferences.intro') }}</div>

                        @foreach ($groups as $group => $prefs)
                            <fieldset name="preferences-{{ $group }}">
                                <x-form.legend>{{ trans('admin/settings/preferences.groups.'.$group) }}</x-form.legend>

                                @foreach ($prefs as $pref)
                                    @php
                                        $key = $pref['key'];
                                        $id = 'pref-'.str_replace('.', '-', $key);
                                        $name = 'prefs['.$key.']';
                                        $current = $oldPrefs[$key] ?? $pref['value'];
                                    @endphp
                                    <div class="form-group {{ $errors->has($key) ? 'error' : '' }}">
                                        <label for="{{ $id }}" class="col-md-3 control-label">{{ $pref['label'] }}</label>
                                        <div class="col-md-9">
                                            @switch($pref['type'])
                                                @case('bool')
                                                    <input type="hidden" name="{{ $name }}" value="0">
                                                    <label class="form-control">
                                                        <input type="checkbox" name="{{ $name }}" id="{{ $id }}" value="1" @checked($pref['value'])>
                                                        {{ $pref['label'] }}
                                                    </label>
                                                    @break
                                                @case('month')
                                                    <select name="{{ $name }}" id="{{ $id }}" class="form-control" style="width: 220px">
                                                        @foreach ($months as $number => $monthName)
                                                            <option value="{{ $number }}" @selected((int) $pref['value'] === $number)>{{ $monthName }}</option>
                                                        @endforeach
                                                    </select>
                                                    @break
                                                @case('status_label')
                                                    <select name="{{ $name }}" id="{{ $id }}" class="form-control select2" style="width: 100%">
                                                        @foreach (array_unique(array_merge($statusLabels, [$pref['value']])) as $label)
                                                            <option value="{{ $label }}" @selected(strcasecmp($label, (string) $pref['value']) === 0)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    @break
                                                @case('status_labels')
                                                    @php $selected = array_map('mb_strtolower', (array) $pref['value']); @endphp
                                                    <input type="hidden" name="{{ $name }}[]" value="">
                                                    <select name="{{ $name }}[]" id="{{ $id }}" class="form-control select2" multiple style="width: 100%">
                                                        @foreach ($statusLabels as $label)
                                                            <option value="{{ $label }}" @selected(in_array(mb_strtolower($label), $selected, true))>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    @break
                                                @case('list')
                                                @case('email_list')
                                                    <textarea name="{{ $name }}" id="{{ $id }}" class="form-control" rows="3">{{ implode("\n", (array) $pref['value']) }}</textarea>
                                                    <p class="help-block">{{ trans('admin/settings/preferences.list_hint') }}</p>
                                                    @break
                                                @default
                                                    <input type="text" name="{{ $name }}" id="{{ $id }}" class="form-control" style="width: 220px"
                                                           value="{{ is_scalar($current) ? $current : '' }}">
                                            @endswitch

                                            {!! $errors->first($key, '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                            <p class="help-block">
                                                {{ $pref['help'] }}
                                                {{ trans('admin/settings/preferences.default') }}: <code>{{ $display($pref['default']) }}</code>
                                            </p>
                                            @if ($pref['overridden'])
                                                <label>
                                                    <span class="label label-warning">{{ trans('admin/settings/preferences.overridden') }}</span>
                                                    <input type="checkbox" name="reset[{{ $key }}]" value="1">
                                                    {{ trans('admin/settings/preferences.reset') }}
                                                </label>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </fieldset>
                        @endforeach

                    </div>
                </div>
                <div class="box-footer">
                    <div class="text-left col-md-6">
                        <a class="btn btn-link text-left" href="{{ route('settings.index') }}">{{ trans('button.cancel') }}</a>
                    </div>
                    <div class="text-right col-md-6">
                        <button type="submit" class="btn btn-primary"><x-icon type="checkmark"/> {{ trans('general.save') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </form>

@stop
