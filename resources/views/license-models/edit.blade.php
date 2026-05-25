@extends('layouts/edit-form', [
    'createText' => trans('admin/licensemodels/general.create'),
    'updateText' => trans('admin/licensemodels/general.update'),
    'topSubmit' => true,
    'formAction' => ($item->id) ? route('license-models.update', ['licenseModel' => $item->id]) : route('license-models.store'),
    'index_route' => 'license-models.index',
])

@section('inputFields')

<div class="form-group {{ $errors->has('name') ? ' has-error' : '' }}">
    <label for="name" class="col-md-3 control-label">{{ trans('general.name') }}<sup>*</sup></label>
    <div class="col-md-7">
        <input class="form-control" type="text" name="name" id="name" value="{{ old('name', $item->name) }}" required maxlength="255">
        {!! $errors->first('name', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<div class="form-group {{ $errors->has('type_code') ? ' has-error' : '' }}">
    <label for="type_code" class="col-md-3 control-label">{{ trans('admin/licensemodels/general.type_code') }}<sup>*</sup></label>
    <div class="col-md-7">
        <input class="form-control" type="text" name="type_code" id="type_code" value="{{ old('type_code', $item->type_code) }}" required maxlength="50" pattern="[a-z0-9_-]+" placeholder="e.g. saas, product_key">
        <p class="help-block">{{ trans('admin/licensemodels/general.type_code_help') }}</p>
        {!! $errors->first('type_code', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<div class="form-group {{ $errors->has('description') ? ' has-error' : '' }}">
    <label for="description" class="col-md-3 control-label">{{ trans('admin/licensemodels/general.description') }}</label>
    <div class="col-md-7">
        <textarea class="form-control" name="description" id="description" rows="3" maxlength="1000">{{ old('description', $item->description) }}</textarea>
        {!! $errors->first('description', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<div class="form-group {{ $errors->has('icon') ? ' has-error' : '' }}">
    <label for="icon" class="col-md-3 control-label">{{ trans('general.icon') }}</label>
    <div class="col-md-7">
        <input class="form-control" type="text" name="icon" id="icon" value="{{ old('icon', $item->icon) }}" maxlength="64" placeholder="fa-key">
        <p class="help-block">{{ trans('admin/licensemodels/general.icon_help') }}</p>
    </div>
</div>

<hr>
<h4 class="col-md-offset-3">{{ trans('admin/licensemodels/general.behavior_flags') }}</h4>
<p class="col-md-offset-3 text-muted col-md-7">{{ trans('admin/licensemodels/general.flags_help') }}</p>

@foreach ([
    'has_seats'        => 'flag_has_seats',
    'has_product_key'  => 'flag_has_product_key',
    'has_checkout'     => 'flag_has_checkout',
    'has_expiration'   => 'flag_has_expiration',
    'has_user_email'   => 'flag_has_user_email',
    'has_reassignable' => 'flag_has_reassignable',
    'is_subscription'  => 'flag_is_subscription',
] as $field => $label_key)
<div class="form-group">
    <div class="col-md-3 control-label">
        <strong>{{ trans('admin/licensemodels/general.'.$label_key) }}</strong>
    </div>
    <div class="col-md-7">
        <label class="form-control">
            <input type="checkbox" name="{{ $field }}" value="1" aria-label="{{ $field }}" @checked(old($field, $item->{$field} ?? true))>
            {{ trans('general.yes') }}
        </label>
    </div>
</div>
@endforeach

<hr>
<h4 class="col-md-offset-3">{{ trans('admin/licensemodels/general.defaults') }}</h4>

<div class="form-group {{ $errors->has('default_seats') ? ' has-error' : '' }}">
    <label for="default_seats" class="col-md-3 control-label">{{ trans('admin/licensemodels/general.default_seats') }}</label>
    <div class="col-md-2">
        <input class="form-control" type="number" min="0" name="default_seats" id="default_seats" value="{{ old('default_seats', $item->default_seats ?? 1) }}">
        {!! $errors->first('default_seats', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<div class="form-group">
    <div class="col-md-3 control-label">
        <strong>{{ trans('admin/licensemodels/general.flag_default_reassignable') }}</strong>
    </div>
    <div class="col-md-7">
        <label class="form-control">
            <input type="checkbox" name="default_reassignable" value="1" aria-label="default_reassignable" @checked(old('default_reassignable', $item->default_reassignable ?? true))>
            {{ trans('general.yes') }}
        </label>
    </div>
</div>

@stop
