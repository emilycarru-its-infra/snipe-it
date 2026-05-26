@extends('layouts/edit-form', [
    'createText' => trans('admin/contracts/general.create'),
    'updateText' => trans('admin/contracts/general.update'),
    'helpTitle' => trans('admin/contracts/general.about_contracts_title'),
    'helpText' => trans('admin/contracts/general.about_contracts_text'),
    'formAction' => (isset($item->id)) ? route('contracts.update', ['contract' => $item->id]) : route('contracts.store'),
])

@section('inputFields')

@include ('partials.forms.edit.name', ['translated_name' => trans('admin/contracts/general.name')])

<div class="form-group {{ $errors->has('contract_number') ? ' has-error' : '' }}">
    <label for="contract_number" class="col-md-3 control-label">{{ trans('admin/contracts/general.contract_number') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="contract_number" type="text" id="contract_number" value="{{ old('contract_number', $item->contract_number) }}" required>
        {!! $errors->first('contract_number', '<span class="alert-msg"><i class="fas fa-times"></i> :message</span>') !!}
    </div>
</div>

<div class="form-group {{ $errors->has('theme') ? ' has-error' : '' }}">
    <label for="theme" class="col-md-3 control-label">{{ trans('admin/contracts/general.theme') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="theme" type="text" id="theme" value="{{ old('theme', $item->theme) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('product') ? ' has-error' : '' }}">
    <label for="product" class="col-md-3 control-label">{{ trans('admin/contracts/general.product') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="product" type="text" id="product" value="{{ old('product', $item->product) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('fiscal_year') ? ' has-error' : '' }}">
    <label for="fiscal_year" class="col-md-3 control-label">{{ trans('admin/contracts/general.fiscal_year') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="fiscal_year" type="text" id="fiscal_year" placeholder="FY25-26" value="{{ old('fiscal_year', $item->fiscal_year) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('parent_contract_id') ? ' has-error' : '' }}">
    <label for="parent_contract_id" class="col-md-3 control-label">{{ trans('admin/contracts/general.parent') }}</label>
    <div class="col-md-7">
        <select class="form-control" name="parent_contract_id" id="parent_contract_id">
            <option value="">{{ trans('admin/contracts/general.no_parent') }}</option>
            @foreach (\App\Models\Contract::umbrellas()->orderBy('name')->get() as $candidate)
                @continue($item->id && $candidate->id === $item->id)
                <option value="{{ $candidate->id }}" @selected(old('parent_contract_id', $item->parent_contract_id) == $candidate->id)>
                    {{ $candidate->name }}
                </option>
            @endforeach
        </select>
    </div>
</div>

@include('partials.forms.edit.supplier-select', ['translated_name' => trans('general.supplier'), 'fieldname' => 'supplier_id'])

@include('partials.forms.edit.user-select', [
    'translated_name' => trans('admin/contracts/general.admin_user'),
    'fieldname'       => 'admin_user_id',
    'hide_new'        => 'true',
])

<div class="form-group {{ $errors->has('type') ? ' has-error' : '' }}">
    <label for="type" class="col-md-3 control-label">{{ trans('admin/contracts/general.contract_type') }}</label>
    <div class="col-md-7">
        <select class="form-control" name="type" id="type">
            <option value="">—</option>
            @foreach (['ServiceContract', 'SupportContract', 'Warranty', 'UpgradeProtection', 'Lease', 'Other'] as $opt)
                <option value="{{ $opt }}" @selected(old('type', $item->type) === $opt)>{{ $opt }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group {{ $errors->has('workflow_status') ? ' has-error' : '' }}">
    <label for="workflow_status" class="col-md-3 control-label">{{ trans('admin/contracts/general.workflow_status') }}</label>
    <div class="col-md-7">
        <select class="form-control" name="workflow_status" id="workflow_status">
            <option value="">—</option>
            @foreach (['Complete', 'In Process', 'On Hold', 'Cancelled'] as $opt)
                <option value="{{ $opt }}" @selected(old('workflow_status', $item->workflow_status) === $opt)>{{ $opt }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group {{ $errors->has('start_date') ? ' has-error' : '' }}">
    <label for="start_date" class="col-md-3 control-label">{{ trans('admin/contracts/general.start_date') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="start_date" type="date" id="start_date" value="{{ old('start_date', optional($item->start_date)->toDateString()) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('end_date') ? ' has-error' : '' }}">
    <label for="end_date" class="col-md-3 control-label">{{ trans('admin/contracts/general.end_date') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="end_date" type="date" id="end_date" value="{{ old('end_date', optional($item->end_date)->toDateString()) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('total_cost') ? ' has-error' : '' }}">
    <label for="total_cost" class="col-md-3 control-label">{{ trans('admin/contracts/general.total_cost') }}</label>
    <div class="col-md-4">
        <input class="form-control" name="total_cost" type="number" step="0.01" min="0" id="total_cost" value="{{ old('total_cost', $item->total_cost) }}">
    </div>
    <div class="col-md-3">
        <input class="form-control" name="currency" type="text" maxlength="3" id="currency" value="{{ old('currency', $item->currency ?? 'CAD') }}">
    </div>
</div>

<div class="form-group {{ $errors->has('gl_code') ? ' has-error' : '' }}">
    <label for="gl_code" class="col-md-3 control-label">{{ trans('admin/contracts/general.gl_code') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="gl_code" type="text" id="gl_code" value="{{ old('gl_code', $item->gl_code) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('requisition_number') ? ' has-error' : '' }}">
    <label for="requisition_number" class="col-md-3 control-label">{{ trans('admin/contracts/general.requisition_number') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="requisition_number" type="text" id="requisition_number" value="{{ old('requisition_number', $item->requisition_number) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('voucher_number') ? ' has-error' : '' }}">
    <label for="voucher_number" class="col-md-3 control-label">{{ trans('admin/contracts/general.voucher_number') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="voucher_number" type="text" id="voucher_number" value="{{ old('voucher_number', $item->voucher_number) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('service_offering') ? ' has-error' : '' }}">
    <label for="service_offering" class="col-md-3 control-label">{{ trans('admin/contracts/general.service_offering') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="service_offering" type="text" id="service_offering" value="{{ old('service_offering', $item->service_offering) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('schedule_number') ? ' has-error' : '' }}">
    <label for="schedule_number" class="col-md-3 control-label">{{ trans('admin/contracts/general.schedule_number') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="schedule_number" type="text" id="schedule_number" value="{{ old('schedule_number', $item->schedule_number) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('ticket_url') ? ' has-error' : '' }}">
    <label for="ticket_url" class="col-md-3 control-label">{{ trans('admin/contracts/general.ticket_url') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="ticket_url" type="url" id="ticket_url" value="{{ old('ticket_url', $item->ticket_url) }}">
    </div>
</div>

<div class="form-group {{ $errors->has('description') ? ' has-error' : '' }}">
    <label for="description" class="col-md-3 control-label">{{ trans('admin/contracts/general.description') }}</label>
    <div class="col-md-7">
        <textarea class="form-control" name="description" id="description" rows="4">{{ old('description', $item->description) }}</textarea>
    </div>
</div>

<div class="form-group {{ $errors->has('comments_review') ? ' has-error' : '' }}">
    <label for="comments_review" class="col-md-3 control-label">{{ trans('admin/contracts/general.comments_review') }}</label>
    <div class="col-md-7">
        <textarea class="form-control" name="comments_review" id="comments_review" rows="4">{{ old('comments_review', $item->comments_review) }}</textarea>
    </div>
</div>

@include ('partials.forms.edit.notes')

<div class="form-group">
    <div class="col-md-7 col-md-offset-3">
        <label>
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $item->is_active ?? true) ? 'checked' : '' }}>
            {{ trans('admin/contracts/general.is_active') }}
        </label>
    </div>
</div>

@stop
