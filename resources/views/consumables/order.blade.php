@extends('layouts/default')

@section('title')
    {{ trans('admin/consumables/general.order_title') }}
    @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-9">
        <form class="form-horizontal" method="post" action="{{ route('consumables.order.store', $consumable->id) }}" autocomplete="off">
            @csrf

            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ $consumable->name }}</h2>
                </div>

                <div class="box-body">
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{ trans('admin/components/general.remaining') }}</label>
                        <div class="col-md-6">
                            <p class="form-control-static">{{ $consumable->numRemaining() }} / {{ $consumable->qty }}</p>
                        </div>
                    </div>

                    @if (! is_null($consumable->min_amt) && (int) $consumable->min_amt > 0)
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{ trans('general.min_amt') }}</label>
                            <div class="col-md-6">
                                <p class="form-control-static">{{ $consumable->min_amt }}</p>
                            </div>
                        </div>
                    @endif

                    {{-- Target: existing planned order vs new one. The two
                         radios drive the visibility of the dependent inputs
                         via the small inline script below. --}}
                    <div class="form-group {{ $errors->has('target') ? 'has-error' : '' }}">
                        <label class="col-sm-3 control-label">{{ trans('admin/consumables/general.order_target') }}</label>
                        <div class="col-md-7">
                            <label class="radio-inline">
                                <input type="radio" name="target" value="existing" {{ old('target', $plannedOrders->isNotEmpty() ? 'existing' : 'new') === 'existing' ? 'checked' : '' }} {{ $plannedOrders->isEmpty() ? 'disabled' : '' }}>
                                {{ trans('admin/consumables/general.order_target_existing') }}
                            </label>
                            <label class="radio-inline">
                                <input type="radio" name="target" value="new" {{ old('target', $plannedOrders->isNotEmpty() ? 'existing' : 'new') === 'new' ? 'checked' : '' }}>
                                {{ trans('admin/consumables/general.order_target_new') }}
                            </label>
                            {!! $errors->first('target', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
                        </div>
                    </div>

                    {{-- Existing planned order dropdown. --}}
                    <div id="order-target-existing" class="form-group {{ $errors->has('order_id') ? 'has-error' : '' }}" style="{{ old('target', $plannedOrders->isNotEmpty() ? 'existing' : 'new') === 'existing' ? '' : 'display: none;' }}">
                        <label class="col-sm-3 control-label" for="order_id">{{ trans('admin/consumables/general.order_planned_order') }}</label>
                        <div class="col-md-7">
                            <select name="order_id" id="order_id" class="form-control">
                                @foreach ($plannedOrders as $plannedOrder)
                                    <option value="{{ $plannedOrder->id }}" {{ (int) old('order_id') === (int) $plannedOrder->id ? 'selected' : '' }}>
                                        {{ $plannedOrder->order_number }}@if ($plannedOrder->fiscal_year) — {{ $plannedOrder->fiscal_year }}@endif
                                    </option>
                                @endforeach
                            </select>
                            {!! $errors->first('order_id', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
                        </div>
                    </div>

                    {{-- New planned order fields. --}}
                    <div id="order-target-new" style="{{ old('target', $plannedOrders->isNotEmpty() ? 'existing' : 'new') === 'new' ? '' : 'display: none;' }}">
                        <div class="form-group {{ $errors->has('new_order_number') ? 'has-error' : '' }}">
                            <label class="col-sm-3 control-label" for="new_order_number">{{ trans('general.order_number') }}</label>
                            <div class="col-md-7">
                                <input type="text" name="new_order_number" id="new_order_number" class="form-control" value="{{ old('new_order_number') }}" maxlength="191">
                                {!! $errors->first('new_order_number', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
                            </div>
                        </div>

                        <div class="form-group {{ $errors->has('fiscal_year') ? 'has-error' : '' }}">
                            <label class="col-sm-3 control-label" for="fiscal_year">{{ trans('admin/purchase-orders/general.fiscal_year') }}</label>
                            <div class="col-md-7">
                                <input type="text" name="fiscal_year" id="fiscal_year" class="form-control" value="{{ old('fiscal_year') }}" maxlength="191" placeholder="FY2025-26">
                                {!! $errors->first('fiscal_year', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
                            </div>
                        </div>
                    </div>

                    <div class="form-group {{ $errors->has('quantity') ? 'has-error' : '' }}">
                        <label class="col-sm-3 control-label" for="quantity">{{ trans('general.qty') }}</label>
                        <div class="col-md-2">
                            <input type="number" name="quantity" id="quantity" class="form-control" min="1" value="{{ old('quantity', 1) }}" required>
                            {!! $errors->first('quantity', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
                        </div>
                    </div>

                    <div class="form-group {{ $errors->has('unit_cost') ? 'has-error' : '' }}">
                        <label class="col-sm-3 control-label" for="unit_cost">{{ trans('general.unit_cost') }}</label>
                        <div class="col-md-3">
                            <input type="number" step="0.01" min="0" name="unit_cost" id="unit_cost" class="form-control" value="{{ old('unit_cost', $consumable->purchase_cost) }}">
                            <p class="help-block">{{ trans('admin/consumables/general.order_unit_cost_help') }}</p>
                        </div>
                    </div>
                </div>

                <div class="box-footer text-right">
                    <a href="{{ route('consumables.show', $consumable->id) }}" class="btn btn-default">{{ trans('button.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-cart-plus"></i>
                        {{ trans('admin/consumables/general.order_submit') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
    $(function () {
        $('input[name=target]').on('change', function () {
            var target = $('input[name=target]:checked').val();
            $('#order-target-existing').toggle(target === 'existing');
            $('#order-target-new').toggle(target === 'new');
        });
    });
</script>
@stop
