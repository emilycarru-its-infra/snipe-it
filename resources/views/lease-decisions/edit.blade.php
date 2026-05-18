@extends('layouts/edit-form', [
    'createText' => trans('admin/lease-decisions/general.create'),
    'updateText' => trans('admin/lease-decisions/general.update'),
    'formAction' => (isset($item->id)) ? route('lease-decisions.update', ['lease_decision' => $item->id]) : route('lease-decisions.store'),
    'index_route' => 'lease-decisions.index',
])

{{-- Page content --}}
@section('inputFields')

<!-- Lease / Contract -->
<div class="form-group {{ $errors->has('contract_reference') ? ' has-error' : '' }}">
    <label for="contract_reference" class="col-md-3 control-label">{{ trans('admin/lease-decisions/general.contract_reference') }}</label>
    <div class="col-md-7 col-sm-12">
        <input class="form-control" type="text" name="contract_reference" id="contract_reference" value="{{ old('contract_reference', $item->contract_reference) }}" maxlength="191" />
        {!! $errors->first('contract_reference', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Decision Type -->
<div class="form-group {{ $errors->has('decision_type') ? ' has-error' : '' }}">
    <label for="decision_type" class="col-md-3 control-label">{{ trans('admin/lease-decisions/general.decision_type') }}</label>
    <div class="col-md-7 col-sm-12">
        @php $current_type = old('decision_type', $item->decision_type ?: 'buyout'); @endphp
        <select class="form-control" name="decision_type" id="decision_type" aria-label="decision_type">
            @foreach (\App\Models\LeaseDecision::DECISION_TYPES as $type_option)
                <option value="{{ $type_option }}" {{ $current_type === $type_option ? 'selected' : '' }}>
                    {{ trans('admin/lease-decisions/general.type_'.$type_option) }}
                </option>
            @endforeach
        </select>
        {!! $errors->first('decision_type', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<!-- Status -->
<div class="form-group {{ $errors->has('status') ? ' has-error' : '' }}">
    <label for="status" class="col-md-3 control-label">{{ trans('admin/lease-decisions/general.status') }}</label>
    <div class="col-md-7 col-sm-12">
        @php $current_status = old('status', $item->status ?: 'pending'); @endphp
        <select class="form-control" name="status" id="status" aria-label="status">
            @foreach (\App\Models\LeaseDecision::STATUSES as $status_option)
                <option value="{{ $status_option }}" {{ $current_status === $status_option ? 'selected' : '' }}>
                    {{ trans('admin/lease-decisions/general.status_'.$status_option) }}
                </option>
            @endforeach
        </select>
        {!! $errors->first('status', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

@include ('partials.forms.edit.datepicker', ['translated_name' => trans('admin/lease-decisions/general.decision_date'), 'fieldname' => 'decision_date'])

<!-- Cost Impact -->
<div class="form-group {{ $errors->has('amount') ? ' has-error' : '' }}">
    <label for="amount" class="col-md-3 control-label">{{ trans('admin/lease-decisions/general.amount') }}</label>
    <div class="col-md-3 col-sm-12">
        <input class="form-control" type="text" name="amount" id="amount" value="{{ old('amount', $item->amount !== null ? number_format($item->amount, 2, '.', '') : '') }}" maxlength="20" />
        {!! $errors->first('amount', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

@include ('partials.forms.edit.notes')

@stop
