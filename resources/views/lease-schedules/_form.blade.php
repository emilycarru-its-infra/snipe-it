@php($stages = \App\Models\LeaseSchedule::LIFECYCLE_STAGES)

<div class="form-group {{ $errors->has('schedule_ref') ? 'has-error' : '' }}">
    <label for="schedule_ref" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.schedule_ref') }}</label>
    <div class="col-md-7">
        <input type="text" id="schedule_ref" name="schedule_ref" class="form-control"
               value="{{ old('schedule_ref', $schedule->schedule_ref ?? '') }}" maxlength="191" required>
    </div>
</div>

<div class="form-group">
    <label for="lessor" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.lessor') }}</label>
    <div class="col-md-7">
        <input type="text" id="lessor" name="lessor" class="form-control"
               value="{{ old('lessor', $schedule->lessor ?? '') }}" maxlength="191"
               placeholder="CSI Leasing / CCA Financial">
    </div>
</div>

<div class="form-group">
    <label for="lease_type" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.lease_type') }}</label>
    <div class="col-md-7">
        <input type="text" id="lease_type" name="lease_type" class="form-control"
               value="{{ old('lease_type', $schedule->lease_type ?? '') }}" maxlength="191"
               placeholder="Lease to Return / Lease to Own">
    </div>
</div>

<div class="form-group">
    <label for="term_months" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.term_months') }}</label>
    <div class="col-md-7">
        <input type="number" id="term_months" name="term_months" class="form-control"
               value="{{ old('term_months', $schedule->term_months ?? '') }}" min="1" max="240">
    </div>
</div>

<div class="form-group">
    <label for="received_at" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.received_at') }}</label>
    <div class="col-md-7">
        <input type="date" id="received_at" name="received_at" class="form-control"
               value="{{ old('received_at', optional($schedule->received_at ?? null)->format('Y-m-d')) }}">
    </div>
</div>

<div class="form-group">
    <label for="expected_acquisition_cost" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.expected_acquisition_cost') }}</label>
    <div class="col-md-7">
        <input type="number" step="0.01" id="expected_acquisition_cost" name="expected_acquisition_cost" class="form-control"
               value="{{ old('expected_acquisition_cost', $schedule->expected_acquisition_cost ?? '') }}">
    </div>
</div>

<div class="form-group">
    <label for="expected_asset_count" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.expected_asset_count') }}</label>
    <div class="col-md-7">
        <input type="number" id="expected_asset_count" name="expected_asset_count" class="form-control"
               value="{{ old('expected_asset_count', $schedule->expected_asset_count ?? '') }}" min="0">
    </div>
</div>

<div class="form-group">
    <label for="usage_tag" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.usage_tag') }}</label>
    <div class="col-md-7">
        <input type="text" id="usage_tag" name="usage_tag" class="form-control"
               value="{{ old('usage_tag', $schedule->usage_tag ?? '') }}" maxlength="191"
               placeholder="Curriculum / Admin">
    </div>
</div>

<div class="form-group {{ $errors->has('lifecycle_stage') ? 'has-error' : '' }}">
    <label for="lifecycle_stage" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.lifecycle_stage') }}</label>
    <div class="col-md-7">
        <select id="lifecycle_stage" name="lifecycle_stage" class="form-control select2">
            @foreach ($stages as $stage)
                <option value="{{ $stage }}" {{ old('lifecycle_stage', $schedule->lifecycle_stage ?? 'draft') === $stage ? 'selected' : '' }}>
                    {{ trans('admin/purchase-orders/general.schedule_stage_'.$stage) }}
                </option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="vendor_on_hold" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.vendor_on_hold') }}</label>
    <div class="col-md-7">
        <label style="font-weight:normal">
            <input type="checkbox" id="vendor_on_hold" name="vendor_on_hold" value="1"
                   {{ old('vendor_on_hold', $schedule->vendor_on_hold ?? false) ? 'checked' : '' }}>
            {{ trans('general.yes') }}
        </label>
    </div>
</div>

<div class="form-group">
    <label for="annexure_a_path" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.annexure_a_path') }}</label>
    <div class="col-md-7">
        <input type="text" id="annexure_a_path" name="annexure_a_path" class="form-control"
               value="{{ old('annexure_a_path', $schedule->annexure_a_path ?? '') }}" maxlength="191"
               placeholder="private_uploads/annexures/...">
    </div>
</div>

<div class="form-group">
    <label for="notes" class="col-md-3 control-label">{{ trans('admin/lease-schedules/general.notes') }}</label>
    <div class="col-md-7">
        <textarea id="notes" name="notes" rows="3" class="form-control">{{ old('notes', $schedule->notes ?? '') }}</textarea>
    </div>
</div>
