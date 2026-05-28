@extends('layouts/default')

@section('title')
    {{ trans('admin/forms/general.settings_title') }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <form class="form-horizontal" method="post" action="{{ route('settings.forms.save') }}">
            @csrf

            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/forms/general.settings_title') }}</h2>
                </div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/forms/general.settings_intro') }}</p>

                    <div class="form-group {{ $errors->has('forms_admin_group_prefix') ? 'has-error' : '' }}">
                        <label for="forms_admin_group_prefix" class="col-md-3 control-label">
                            {{ trans('admin/forms/general.settings_prefix_label') }}
                        </label>
                        <div class="col-md-6">
                            <input type="text" id="forms_admin_group_prefix" name="forms_admin_group_prefix"
                                   class="form-control" maxlength="64"
                                   value="{{ old('forms_admin_group_prefix', $setting->forms_admin_group_prefix ?? 'ITS') }}">
                            <p class="help-block">{{ trans('admin/forms/general.settings_prefix_help') }}</p>
                            @if ($matchedAdminGroups->isNotEmpty())
                                <p class="help-block">
                                    {{ trans('admin/forms/general.settings_prefix_matched', ['groups' => $matchedAdminGroups->pluck('name')->join(', ')]) }}
                                </p>
                            @else
                                <p class="help-block text-warning">
                                    {{ trans('admin/forms/general.settings_prefix_matched_none') }}
                                </p>
                            @endif
                            @if ($errors->has('forms_admin_group_prefix'))
                                <p class="help-block">{{ $errors->first('forms_admin_group_prefix') }}</p>
                            @endif
                        </div>
                    </div>

                    @foreach ($modules as $slug => $meta)
                        <div class="form-group">
                            <label for="eligibility_{{ $slug }}" class="col-md-3 control-label">
                                {{ trans('admin/forms/general.settings_eligibility_label', ['form' => trans($meta['label_key'])]) }}
                            </label>
                            <div class="col-md-6">
                                <select id="eligibility_{{ $slug }}" name="eligibility[{{ $slug }}][]" multiple class="form-control" size="6">
                                    @foreach ($groups as $group)
                                        <option value="{{ $group->id }}" {{ in_array($group->id, $eligibility[$slug] ?? []) ? 'selected' : '' }}>
                                            {{ $group->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="help-block">{{ trans('admin/forms/general.settings_eligibility_help') }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="box-footer text-right">
                    <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

@stop
