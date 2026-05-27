@php($agreementTypes = \App\Models\UserAgreement::AGREEMENT_TYPES)
@php($stages = \App\Models\UserAgreement::LIFECYCLE_STAGES)
@php($methods = \App\Models\UserAgreement::PAYMENT_METHODS)

<div class="form-group {{ $errors->has('agreement_type') ? 'has-error' : '' }}">
    <label for="agreement_type" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.type') }}</label>
    <div class="col-md-7">
        <select id="agreement_type" name="agreement_type" class="form-control select2">
            @foreach ($agreementTypes as $type)
                <option value="{{ $type }}" {{ old('agreement_type', $agreement->agreement_type ?? '') === $type ? 'selected' : '' }}>
                    {{ trans('admin/purchase-orders/general.user_agreement_type_value_'.$type) }}
                </option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="user_id" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.user_agreement_member') }}</label>
    <div class="col-md-7">
        {{-- Plain ID input keeps the form light; admins paste the user id
             from /users. Future iteration can wire a Snipe selector. --}}
        <input type="number" id="user_id" name="user_id" class="form-control"
               value="{{ old('user_id', $agreement->user_id ?? '') }}" min="1">
    </div>
</div>

<div class="form-group">
    <label for="asset_id" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.asset') }}</label>
    <div class="col-md-7">
        <input type="number" id="asset_id" name="asset_id" class="form-control"
               value="{{ old('asset_id', $agreement->asset_id ?? '') }}" min="1">
    </div>
</div>

<div class="form-group {{ $errors->has('lifecycle_stage') ? 'has-error' : '' }}">
    <label for="lifecycle_stage" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.lifecycle_stage') }}</label>
    <div class="col-md-7">
        <select id="lifecycle_stage" name="lifecycle_stage" class="form-control select2">
            @foreach ($stages as $stage)
                <option value="{{ $stage }}" {{ old('lifecycle_stage', $agreement->lifecycle_stage ?? 'eligible') === $stage ? 'selected' : '' }}>
                    {{ trans('admin/purchase-orders/general.user_agreement_stage_value_'.$stage) }}
                </option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="base_program_price" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.base_program_price') }}</label>
    <div class="col-md-7"><input type="number" step="0.01" id="base_program_price" name="base_program_price" class="form-control" value="{{ old('base_program_price', $agreement->base_program_price ?? '') }}"></div>
</div>
<div class="form-group">
    <label for="device_cost" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.device_cost') }}</label>
    <div class="col-md-7"><input type="number" step="0.01" id="device_cost" name="device_cost" class="form-control" value="{{ old('device_cost', $agreement->device_cost ?? '') }}"></div>
</div>
<div class="form-group">
    <label for="top_up_amount" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.top_up_amount') }}</label>
    <div class="col-md-7"><input type="number" step="0.01" id="top_up_amount" name="top_up_amount" class="form-control" value="{{ old('top_up_amount', $agreement->top_up_amount ?? '') }}"></div>
</div>
<div class="form-group">
    <label for="buyout_cost" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.buyout_cost') }}</label>
    <div class="col-md-7"><input type="number" step="0.01" id="buyout_cost" name="buyout_cost" class="form-control" value="{{ old('buyout_cost', $agreement->buyout_cost ?? '') }}"></div>
</div>

<div class="form-group">
    <label for="payment_method" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.payment_method') }}</label>
    <div class="col-md-7">
        <select id="payment_method" name="payment_method" class="form-control select2">
            <option value="">—</option>
            @foreach ($methods as $method)
                <option value="{{ $method }}" {{ old('payment_method', $agreement->payment_method ?? '') === $method ? 'selected' : '' }}>
                    {{ trans('admin/purchase-orders/general.user_agreement_payment_value_'.$method) }}
                </option>
            @endforeach
        </select>
    </div>
</div>
<div class="form-group">
    <label for="installment_count" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.installment_count') }}</label>
    <div class="col-md-7"><input type="number" id="installment_count" name="installment_count" class="form-control" value="{{ old('installment_count', $agreement->installment_count ?? '') }}"></div>
</div>

<div class="form-group">
    <label for="old_asset_tag" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.old_asset_tag') }}</label>
    <div class="col-md-7"><input type="text" id="old_asset_tag" name="old_asset_tag" class="form-control" value="{{ old('old_asset_tag', $agreement->old_asset_tag ?? '') }}"></div>
</div>
<div class="form-group">
    <label for="lease_contract" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.lease_contract') }}</label>
    <div class="col-md-7"><input type="text" id="lease_contract" name="lease_contract" class="form-control" value="{{ old('lease_contract', $agreement->lease_contract ?? '') }}"></div>
</div>

<div class="form-group">
    <label for="notes" class="col-md-3 control-label">{{ trans('admin/user-agreements/general.notes') }}</label>
    <div class="col-md-7">
        <textarea id="notes" name="notes" rows="3" class="form-control">{{ old('notes', $agreement->notes ?? '') }}</textarea>
    </div>
</div>
