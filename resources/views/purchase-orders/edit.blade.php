@extends('layouts/edit-form', [
    'createText' => trans('admin/purchase-orders/general.create'),
    'updateText' => trans('admin/purchase-orders/general.update'),
    'formAction' => (isset($item->id)) ? route('purchase-orders.update', ['purchase_order' => $item->id]) : route('purchase-orders.store'),
    'index_route' => 'purchase-orders.index',
])

{{-- Page content --}}
@section('inputFields')

<!-- PO Number -->
<div class="form-group {{ $errors->has('po_number') ? ' has-error' : '' }}">
    <label for="po_number" class="col-md-3 control-label">{{ trans('admin/purchase-orders/general.po_number') }}</label>
    <div class="col-md-7 col-sm-12">
        <input class="form-control" type="text" name="po_number" id="po_number" value="{{ old('po_number', $item->po_number) }}" maxlength="191" />
        {!! $errors->first('po_number', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Title -->
<div class="form-group {{ $errors->has('title') ? ' has-error' : '' }}">
    <label for="title" class="col-md-3 control-label">{{ trans('admin/purchase-orders/general.title') }}</label>
    <div class="col-md-7 col-sm-12">
        <input class="form-control" type="text" name="title" id="title" value="{{ old('title', $item->title) }}" maxlength="191" />
        {!! $errors->first('title', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Status -->
<div class="form-group {{ $errors->has('status') ? ' has-error' : '' }}">
    <label for="status" class="col-md-3 control-label">{{ trans('admin/purchase-orders/general.status') }}</label>
    <div class="col-md-7 col-sm-12">
        @php $current_status = old('status', $item->status ?: 'open'); @endphp
        <select class="form-control" name="status" id="status" aria-label="status">
            @foreach (\App\Models\PurchaseOrder::STATUSES as $status_option)
                <option value="{{ $status_option }}" {{ $current_status === $status_option ? 'selected' : '' }}>
                    {{ trans('admin/purchase-orders/general.status_'.$status_option) }}
                </option>
            @endforeach
        </select>
        {!! $errors->first('status', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

@include ('partials.forms.edit.supplier-select', ['translated_name' => trans('general.supplier'), 'fieldname' => 'supplier_id'])
@include ('partials.forms.edit.company-select', ['translated_name' => trans('general.company'), 'fieldname' => 'company_id'])

<!-- Fiscal Year -->
<div class="form-group {{ $errors->has('fiscal_year') ? ' has-error' : '' }}">
    <label for="fiscal_year" class="col-md-3 control-label">{{ trans('admin/purchase-orders/general.fiscal_year') }}</label>
    <div class="col-md-7 col-sm-12">
        <input class="form-control" type="text" name="fiscal_year" id="fiscal_year" value="{{ old('fiscal_year', $item->fiscal_year) }}" maxlength="191" />
        {!! $errors->first('fiscal_year', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Budget -->
<div class="form-group {{ $errors->has('budget') ? ' has-error' : '' }}">
    <label for="budget" class="col-md-3 control-label">{{ trans('admin/purchase-orders/general.budget') }}</label>
    <div class="col-md-3 col-sm-12">
        <input class="form-control" type="text" name="budget" id="budget" value="{{ old('budget', $item->budget !== null ? number_format($item->budget, 2, '.', '') : '') }}" maxlength="20" />
        {!! $errors->first('budget', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Cost Center -->
<div class="form-group {{ $errors->has('cost_center') ? ' has-error' : '' }}">
    <label for="cost_center" class="col-md-3 control-label">{{ trans('admin/purchase-orders/general.cost_center') }}</label>
    <div class="col-md-7 col-sm-12">
        <input class="form-control" type="text" name="cost_center" id="cost_center" value="{{ old('cost_center', $item->cost_center) }}" maxlength="191" />
        {!! $errors->first('cost_center', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

@include ('partials.forms.edit.datepicker', ['translated_name' => trans('admin/purchase-orders/general.order_date'), 'fieldname' => 'order_date'])
@include ('partials.forms.edit.notes')

@stop
