@extends('layouts/edit-form', [
    'createText' => trans('admin/orders/general.create'),
    'updateText' => trans('admin/orders/general.update'),
    'formAction' => (isset($item->id)) ? route('orders.update', ['order' => $item->id]) : route('orders.store'),
    'index_route' => 'orders.index',
])

{{-- Page content --}}
@section('inputFields')

@include ('partials.forms.edit.order_number')

<!-- Status -->
<div class="form-group {{ $errors->has('status') ? ' has-error' : '' }}">
    <label for="status" class="col-md-3 control-label">{{ trans('admin/orders/general.status') }}</label>
    <div class="col-md-7 col-sm-12">
        @php
            $current_status = old('status', $item->status ?: 'ordered');
        @endphp
        <select class="form-control" name="status" id="status" aria-label="status">
            @foreach (\App\Models\Order::STATUSES as $status_option)
                <option value="{{ $status_option }}" {{ $current_status === $status_option ? 'selected' : '' }}>
                    {{ trans('admin/orders/general.status_'.$status_option) }}
                </option>
            @endforeach
        </select>
        <p class="help-block">{{ trans('admin/orders/general.status_help') }}</p>
        {!! $errors->first('status', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
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

@include ('partials.forms.edit.tracking')
@include ('partials.forms.edit.notes')

@stop
