@extends('layouts/edit-form', [
    'createText' => trans('admin/consumables/general.create') ,
    'updateText' => trans('admin/consumables/general.update'),
    'helpPosition'  => 'right',
    'helpText' => trans('help.consumables'),
    'formAction' => (isset($item->id)) ? route('consumables.update', ['consumable' => $item->id]) : route('consumables.store'),
    'index_route' => 'consumables.index',
    'options' => [
                'back' => trans('admin/hardware/form.redirect_to_type',['type' => trans('general.previous_page')]),
                'index' => trans('admin/hardware/form.redirect_to_all', ['type' => 'consumables']),
                'item' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.consumable')]),
               ]
])
{{-- Page content --}}
@section('inputFields')

@include ('partials.forms.edit.company-select', ['translated_name' => trans('general.company'), 'fieldname' => 'company_id'])
@include ('partials.forms.edit.name', ['translated_name' => trans('general.name')])
@include ('partials.forms.edit.category-select', ['translated_name' => trans('general.category'), 'fieldname' => 'category_id', 'required' => 'true', 'category_type' => 'consumable'])
@include ('partials.forms.edit.quantity')
@include ('partials.forms.edit.minimum_quantity')
@include ('partials.forms.edit.consumable_status')
@include ('partials.forms.edit.supplier-select', ['translated_name' => trans('general.supplier'), 'fieldname' => 'supplier_id'])
@include ('partials.forms.edit.manufacturer-select', ['translated_name' => trans('general.manufacturer'), 'fieldname' => 'manufacturer_id'])
@include ('partials.forms.edit.location-select', ['translated_name' => trans('general.location'), 'fieldname' => 'location_id'])

{{-- Compatible asset models: optional whitelist. When set, checkout-to-asset
     only lists assets of these models (e.g. a toner that fits specific printer
     models). Empty means the consumable can be checked out to any asset. --}}
<div class="form-group">
    <label class="col-md-3 control-label" for="compatible_models">{{ trans('admin/consumables/general.compatible_models') }}</label>
    <div class="col-md-7">
        <select
            class="js-data-ajax select2"
            data-endpoint="models"
            data-placeholder="{{ trans('admin/consumables/general.compatible_models_placeholder') }}"
            name="compatible_models[]"
            id="compatible_models"
            aria-label="compatible_models"
            multiple="multiple"
            style="width: 100%">
            @php
                $oldCompatible = old('compatible_models');
                if ($oldCompatible === null) {
                    $oldCompatible = isset($item) ? $item->compatibleModels->pluck('id')->all() : [];
                }
            @endphp
            @foreach ((array) $oldCompatible as $compatibleModelId)
                <option value="{{ $compatibleModelId }}" selected="selected">
                    {{ optional(\App\Models\AssetModel::find($compatibleModelId))->name }}
                </option>
            @endforeach
        </select>
        <p class="help-block">{{ trans('admin/consumables/general.compatible_models_help') }}</p>
    </div>
</div>
@include ('partials.forms.edit.model_number')
@include ('partials.forms.edit.item_number')
@include ('partials.forms.edit.order_number')
@include ('partials.forms.edit.tracking')
@include ('partials.forms.edit.datepicker', ['translated_name' => trans('general.purchase_date'),'fieldname' => 'purchase_date'])
@include ('partials.forms.edit.purchase_cost', [ 'unit_cost' => trans('general.unit_cost')])
@include ('partials.forms.edit.on_maintenance_contract')
@include ('partials.forms.edit.notes')
@include ('partials.forms.edit.image-upload', ['image_path' => app('consumables_upload_path')])

@stop
