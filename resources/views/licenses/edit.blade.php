@extends('layouts/edit-form', [
    'createText' => trans('admin/licenses/form.create'),
    'updateText' => trans('admin/licenses/form.update'),
    'topSubmit' => true,
    'formAction' => ($item->id) ? route('licenses.update', ['license' => $item->id]) : route('licenses.store'),
     'index_route' => 'licenses.index',
    'options' => [
                'back' => trans('admin/hardware/form.redirect_to_type',['type' => trans('general.previous_page')]),
                'index' => trans('admin/hardware/form.redirect_to_all', ['type' => 'licenses']),
                'item' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.license')]),
               ]
])

{{-- Page content --}}
@section('inputFields')
@include ('partials.forms.edit.name', ['translated_name' => trans('admin/licenses/form.name')])
@include ('partials.forms.edit.category-select', ['translated_name' => trans('admin/categories/general.category_name'), 'fieldname' => 'category_id', 'required' => 'true', 'category_type' => 'license'])

{{-- License Model (categorizes license by behavior: SaaS / product-key / etc.) --}}
@php
    $licenseModels = $license_models ?? collect();
    $selectedModelId = old('license_model_id', $item->license_model_id);
    // Build a JSON map of model_id -> flags for the JS toggler below.
    $modelFlags = $licenseModels->mapWithKeys(fn ($m) => [$m->id => [
        'has_seats'        => (bool) $m->has_seats,
        'has_product_key'  => (bool) $m->has_product_key,
        'has_checkout'     => (bool) $m->has_checkout,
        'has_expiration'   => (bool) $m->has_expiration,
        'has_user_email'   => (bool) $m->has_user_email,
        'has_reassignable' => (bool) $m->has_reassignable,
        'is_subscription'  => (bool) $m->is_subscription,
        'type_code'        => $m->type_code,
    ]]);
@endphp
<div class="form-group {{ $errors->has('license_model_id') ? ' has-error' : '' }}">
    <label for="license_model_id" class="col-md-3 control-label">{{ trans('admin/licensemodels/general.license_type') }}</label>
    <div class="col-md-7">
        <select class="form-control" name="license_model_id" id="license_model_id" data-license-model-toggler>
            <option value="">{{ trans('admin/licensemodels/general.default_product_key') }}</option>
            @foreach ($licenseModels as $m)
                <option value="{{ $m->id }}" @selected((string) $selectedModelId === (string) $m->id)>
                    {{ $m->name }}@if ($m->description) — {{ \Illuminate\Support\Str::limit($m->description, 80) }}@endif
                </option>
            @endforeach
        </select>
        <p class="help-block">{{ trans('admin/licensemodels/general.license_type_help') }}</p>
        {!! $errors->first('license_model_id', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Seats -->
<div class="form-group {{ $errors->has('seats') ? ' has-error' : '' }}" data-license-flag="has_seats">
    <label for="seats" class="col-md-3 control-label">{{ trans('admin/licenses/form.seats') }}</label>
    <div class="col-md-7 col-sm-12">
        <div class="col-md-12" style="padding-left:0px">
            <input class="form-control" type="number" min="0" name="seats" id="seats" value="{{ old('seats', $item->seats) }}" minlength="1" required style="width: 97px;">
        </div>
    </div>
    {!! $errors->first('seats', '<div class="col-md-8 col-md-offset-3"><span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span></div>') !!}
</div>
<div data-license-flag="has_seats">
@include ('partials.forms.edit.minimum_quantity')
</div>

<!-- Serial-->
@can('viewKeys', $item)
    <div class="form-group {{ $errors->has('serial') ? ' has-error' : '' }}" data-license-flag="has_product_key">
        <label for="serial" class="col-md-3 control-label">{{ trans('admin/licenses/form.license_key') }}</label>
        <div class="col-md-7">
            <textarea class="form-control" type="text" name="serial" id="serial" rows="5"{{  (Helper::checkIfRequired($item, 'serial')) ? ' required' : '' }}>{{ old('serial', $item->serial) }}</textarea>
            {!! $errors->first('serial', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
        </div>
    </div>
@endcan

@include ('partials.forms.edit.company-select', ['translated_name' => trans('general.company'), 'fieldname' => 'company_id'])
@include ('partials.forms.edit.manufacturer-select', ['translated_name' => trans('general.manufacturer'), 'fieldname' => 'manufacturer_id',])

<!-- Licensed to name -->
<div class="form-group {{ $errors->has('license_name') ? ' has-error' : '' }}" data-license-flag="has_user_email">
    <label for="license_name" class="col-md-3 control-label">{{ trans('admin/licenses/form.to_name') }}</label>
    <div class="col-md-7">
        <input class="form-control" type="text" name="license_name" id="license_name" value="{{ old('license_name', $item->license_name) }}" />
        {!! $errors->first('license_name', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Licensed to email -->
<div class="form-group {{ $errors->has('license_email') ? ' has-error' : '' }}" data-license-flag="has_user_email">
    <label for="license_email" class="col-md-3 control-label">{{ trans('admin/licenses/form.to_email') }}</label>
    <div class="col-md-7">
        <input class="form-control" type="email" name="license_email" id="license_email" value="{{ old('license_email', $item->license_email) }}" />
        {!! $errors->first('license_email', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Reassignable -->
<div class="form-group {{ $errors->has('reassignable') ? ' has-error' : '' }}" data-license-flag="has_reassignable">
    <div class="col-md-3 control-label">
        <strong>{{ trans('admin/licenses/form.reassignable') }}</strong>
    </div>
    <div class="col-md-7">
        <label class="form-control">
            <input type="checkbox" name="reassignable" value="1" aria-label="reassignable" @checked(old('reassignable', $item->id ? $item->reassignable : '1'))>
        {{ trans('general.yes') }}
        </label>
    </div>
</div>


@include ('partials.forms.edit.supplier-select', ['translated_name' => trans('general.supplier'), 'fieldname' => 'supplier_id'])
@include ('partials.forms.edit.order_number')
@include ('partials.forms.edit.purchase_cost')
@include ('partials.forms.edit.datepicker', ['translated_name' => trans('general.purchase_date'),'fieldname' => 'purchase_date'])

<!-- Expiration Date -->
<div class="form-group {{ $errors->has('expiration_date') ? ' has-error' : '' }}" data-license-flag="has_expiration">
    <label for="expiration_date" class="col-md-3 control-label">{{ trans('admin/licenses/form.expiration') }}</label>

    <div class="input-group col-md-4">
        <div class="input-group date" data-provide="datepicker" data-date-format="yyyy-mm-dd"  data-date-today-btn="true" data-date-today-highlight="true" data-autoclose="true" data-date-clear-btn="true">
            <input type="text" class="form-control" placeholder="{{ trans('general.select_date') }}" name="expiration_date" id="expiration_date" value="{{ old('expiration_date', ($item->expiration_date) ? $item->expiration_date->format('Y-m-d') : '') }}" maxlength="10">
            <span class="input-group-addon"><x-icon type="calendar" /></span>
        </div>
        {!! $errors->first('expiration_date', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>

</div>

<!-- Termination Date -->
<div class="form-group {{ $errors->has('termination_date') ? ' has-error' : '' }}">
    <label for="termination_date" class="col-md-3 control-label">{{ trans('admin/licenses/form.termination_date') }}</label>

    <div class="input-group col-md-4">
        <div class="input-group date" data-provide="datepicker" data-date-today-btn="true" data-date-today-highlight="true" data-date-format="yyyy-mm-dd" data-autoclose="true" data-date-clear-btn="true">
            <input type="text" class="form-control" placeholder="{{ trans('general.select_date') }}" name="termination_date" id="termination_date" value="{{ old('termination_date', ($item->termination_date) ? $item->termination_date->format('Y-m-d') : '') }}" maxlength="10">
            <span class="input-group-addon"><x-icon type="calendar" /></span>
        </div>
        {!! $errors->first('termination_date', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

{{-- @TODO How does this differ from Order #? --}}
<!-- Purchase Order -->
<div class="form-group {{ $errors->has('purchase_order') ? ' has-error' : '' }}">
    <label for="purchase_order" class="col-md-3 control-label">{{ trans('admin/licenses/form.purchase_order') }}</label>
    <div class="col-md-3 text-right">
        <input class="form-control" type="text" name="purchase_order" id="purchase_order" value="{{ old('purchase_order', $item->purchase_order) }}" maxlength="191" />
        {!! $errors->first('purchase_order', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

@include ('partials.forms.edit.depreciation')

<!-- Maintained -->
<div class="form-group {{ $errors->has('maintained') ? ' has-error' : '' }}">
    <div class="col-md-3 control-label"><strong>{{ trans('admin/licenses/form.maintained') }}</strong></div>
    <div class="col-md-7">
        <label class="form-control">
            <input type="checkbox" name="maintained" value="1" aria-label="maintained" @checked(old('maintained', $item->maintained))>
        {{ trans('general.yes') }}
        </label>
    </div>
</div>

@include ('partials.forms.edit.notes')

{{-- License-model field visibility toggler.
     Hides or shows form blocks based on the selected LicenseModel's flags.
     Default ("Product Key") shows everything, matching legacy behavior. --}}
<script>
(function () {
    var flagMap = @json($modelFlags ?? []);
    var defaultFlags = {
        has_seats: true, has_product_key: true, has_checkout: true,
        has_expiration: true, has_user_email: false, has_reassignable: true,
        is_subscription: false
    };

    function applyFlags(flags) {
        document.querySelectorAll('[data-license-flag]').forEach(function (el) {
            var flag = el.getAttribute('data-license-flag');
            el.style.display = flags[flag] ? '' : 'none';
        });
    }

    var sel = document.getElementById('license_model_id');
    if (!sel) return;

    function refresh() {
        var modelId = sel.value;
        var flags = (modelId && flagMap[modelId]) ? flagMap[modelId] : defaultFlags;
        applyFlags(flags);
    }
    sel.addEventListener('change', refresh);
    refresh();
})();
</script>

@stop
