@extends('layouts/edit-form', [
    'createText' => trans('admin/orders/general.create'),
    'updateText' => trans('admin/orders/general.update'),
    'formAction' => (isset($item->id)) ? route('orders.update', ['order' => $item->id]) : route('orders.store'),
    'index_route' => 'orders.index',
])

{{-- Page content --}}
@section('inputFields')

@include ('partials.forms.edit.order_number')

<!-- Purchase Order -->
<div class="form-group {{ $errors->has('purchase_order_id') ? ' has-error' : '' }}">
    <label for="purchase_order_id" class="col-md-3 control-label">{{ trans('admin/purchase-orders/general.purchase_order') }}</label>
    <div class="col-md-7 col-sm-12">
        @php $current_po = old('purchase_order_id', $item->purchase_order_id); @endphp
        <select class="form-control" name="purchase_order_id" id="purchase_order_id" aria-label="purchase_order_id">
            <option value="">{{ trans('admin/purchase-orders/general.none') }}</option>
            @foreach ($purchase_orders as $po)
                <option value="{{ $po->id }}" {{ (int) $current_po === (int) $po->id ? 'selected' : '' }}>{{ $po->po_number }}{{ $po->title ? ' — '.$po->title : '' }}</option>
            @endforeach
        </select>
        {!! $errors->first('purchase_order_id', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Planned order -->
<div class="form-group">
    <label class="col-md-3 control-label">{{ trans('admin/orders/general.is_planned') }}</label>
    <div class="col-md-7 col-sm-12">
        <label class="checkbox-inline">
            <input type="checkbox" name="is_planned" value="1" {{ old('is_planned', $item->is_planned) ? 'checked' : '' }}>
            {{ trans('admin/orders/general.is_planned_help') }}
        </label>
    </div>
</div>

<!-- Fiscal Year -->
<div class="form-group {{ $errors->has('fiscal_year') ? ' has-error' : '' }}">
    <label for="fiscal_year" class="col-md-3 control-label">{{ trans('admin/orders/general.fiscal_year') }}</label>
    <div class="col-md-7 col-sm-12">
        <input class="form-control" type="text" name="fiscal_year" id="fiscal_year" value="{{ old('fiscal_year', $item->fiscal_year) }}" maxlength="191" />
        {!! $errors->first('fiscal_year', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Area -->
<div class="form-group {{ $errors->has('area') ? ' has-error' : '' }}">
    <label for="area" class="col-md-3 control-label">{{ trans('admin/budget-allocations/general.area') }}</label>
    <div class="col-md-7 col-sm-12">
        <input class="form-control" type="text" name="area" id="area" value="{{ old('area', $item->area) }}" maxlength="191" list="known-areas" placeholder="Admin / Curriculum / Faculty Program / Research" />
        <datalist id="known-areas">
            @foreach (\App\Models\Order::query()->whereNotNull('area')->distinct()->orderBy('area')->pluck('area') as $known)
                <option value="{{ $known }}"></option>
            @endforeach
        </datalist>
        <span class="help-block">{{ trans('admin/budget-allocations/general.area_help') }}</span>
        {!! $errors->first('area', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

@include ('partials.forms.edit.supplier-select', ['translated_name' => trans('general.supplier'), 'fieldname' => 'supplier_id'])
@include ('partials.forms.edit.company-select', ['translated_name' => trans('general.company'), 'fieldname' => 'company_id'])
@include ('partials.forms.edit.datepicker', ['translated_name' => trans('admin/orders/general.order_date'), 'fieldname' => 'order_date'])
@include ('partials.forms.edit.datepicker', ['translated_name' => trans('admin/orders/general.expected_date'), 'fieldname' => 'expected_date'])
@include ('partials.forms.edit.datepicker', ['translated_name' => trans('admin/orders/general.received_date'), 'fieldname' => 'received_date'])

<!-- Order cost -->
<div class="form-group {{ $errors->has('order_cost') ? ' has-error' : '' }}">
    <label for="order_cost" class="col-md-3 control-label">{{ trans('admin/orders/general.order_cost') }}</label>
    <div class="col-md-3 col-sm-12">
        <input class="form-control" type="text" name="order_cost" id="order_cost" value="{{ old('order_cost', $item->order_cost !== null ? number_format($item->order_cost, 2, '.', '') : '') }}" maxlength="20" />
        {!! $errors->first('order_cost', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

@include ('partials.forms.edit.notes')

@stop
