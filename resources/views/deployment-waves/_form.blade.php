@php($locations = \App\Models\Location::orderBy('name')->get())

<div class="form-group {{ $errors->has('name') ? 'has-error' : '' }}">
    <label for="name" class="col-md-3 control-label">{{ trans('admin/deployments/general.name') }}</label>
    <div class="col-md-7"><input type="text" id="name" name="name" class="form-control" value="{{ old('name', $wave->name ?? '') }}" required></div>
</div>

<div class="form-group {{ $errors->has('fiscal_year') ? 'has-error' : '' }}">
    <label for="fiscal_year" class="col-md-3 control-label">{{ trans('admin/deployments/general.fiscal_year') }}</label>
    <div class="col-md-7"><input type="text" id="fiscal_year" name="fiscal_year" class="form-control" value="{{ old('fiscal_year', $wave->fiscal_year ?? '') }}" placeholder="FY2027-28"></div>
</div>

<div class="form-group {{ $errors->has('deployment_type_id') ? 'has-error' : '' }}">
    <label for="deployment_type_id" class="col-md-3 control-label">{{ trans('admin/deployments/general.deployment_type') }}</label>
    <div class="col-md-7">
        <select id="deployment_type_id" name="deployment_type_id" class="form-control select2">
            <option value="">—</option>
            @foreach ($types as $t)
                <option value="{{ $t->id }}" {{ (int) old('deployment_type_id', $wave->deployment_type_id ?? 0) === (int) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="wave_state" class="col-md-3 control-label">{{ trans('admin/deployments/general.wave_state') }}</label>
    <div class="col-md-7">
        <select id="wave_state" name="wave_state" class="form-control select2">
            @foreach (\App\Models\DeploymentWave::STATES as $state)
                <option value="{{ $state }}" {{ old('wave_state', $wave->wave_state ?? 'planned') === $state ? 'selected' : '' }}>{{ ucfirst($state) }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="arrival_window_start" class="col-md-3 control-label">{{ trans('admin/deployments/general.arrival_window_start') }}</label>
    <div class="col-md-7"><input type="date" id="arrival_window_start" name="arrival_window_start" class="form-control" value="{{ old('arrival_window_start', optional($wave->arrival_window_start ?? null)->toDateString()) }}"></div>
</div>

<div class="form-group">
    <label for="arrival_window_end" class="col-md-3 control-label">{{ trans('admin/deployments/general.arrival_window_end') }}</label>
    <div class="col-md-7"><input type="date" id="arrival_window_end" name="arrival_window_end" class="form-control" value="{{ old('arrival_window_end', optional($wave->arrival_window_end ?? null)->toDateString()) }}"></div>
</div>

<div class="form-group">
    <label for="target_start_date" class="col-md-3 control-label">{{ trans('admin/deployments/general.target_start_date') }}</label>
    <div class="col-md-7"><input type="date" id="target_start_date" name="target_start_date" class="form-control" value="{{ old('target_start_date', optional($wave->target_start_date ?? null)->toDateString()) }}"></div>
</div>

<div class="form-group">
    <label for="target_end_date" class="col-md-3 control-label">{{ trans('admin/deployments/general.target_end_date') }}</label>
    <div class="col-md-7"><input type="date" id="target_end_date" name="target_end_date" class="form-control" value="{{ old('target_end_date', optional($wave->target_end_date ?? null)->toDateString()) }}"></div>
</div>

<div class="form-group">
    <label for="location_id" class="col-md-3 control-label">{{ trans('admin/deployments/general.location') }}</label>
    <div class="col-md-7">
        <select id="location_id" name="location_id" class="form-control select2">
            <option value="">—</option>
            @foreach ($locations as $loc)
                <option value="{{ $loc->id }}" {{ (int) old('location_id', $wave->location_id ?? 0) === (int) $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="storage_location_id" class="col-md-3 control-label">{{ trans('admin/deployments/general.storage_location') }}</label>
    <div class="col-md-7">
        <select id="storage_location_id" name="storage_location_id" class="form-control select2">
            <option value="">—</option>
            @foreach ($locations as $loc)
                <option value="{{ $loc->id }}" {{ (int) old('storage_location_id', $wave->storage_location_id ?? 0) === (int) $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
            @endforeach
        </select>
    </div>
</div>

@include('partials.forms.edit.user-select', [
    'fieldname' => 'owner_id',
    'translated_name' => trans('admin/deployments/general.owner'),
    'item' => $wave ?? null,
])

<div class="form-group">
    <label for="purchase_order_id" class="col-md-3 control-label">{{ trans('admin/deployments/general.purchase_order') }}</label>
    <div class="col-md-7">
        <select id="purchase_order_id" name="purchase_order_id" class="form-control select2">
            <option value="">—</option>
            @foreach (\App\Models\PurchaseOrder::orderByDesc('id')->limit(500)->get() as $po)
                <option value="{{ $po->id }}" {{ (int) old('purchase_order_id', $wave->purchase_order_id ?? 0) === (int) $po->id ? 'selected' : '' }}>{{ $po->po_number ?? ('#'.$po->id) }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="color" class="col-md-3 control-label">{{ trans('admin/deployments/general.color') }}</label>
    <div class="col-md-7"><input type="color" id="color" name="color" value="{{ old('color', $wave->color ?: '#2980b9') }}" style="height:38px; width:80px;"></div>
</div>

<div class="form-group">
    <label for="notes" class="col-md-3 control-label">{{ trans('admin/deployments/general.notes') }}</label>
    <div class="col-md-7"><textarea id="notes" name="notes" rows="3" class="form-control">{{ old('notes', $wave->notes ?? '') }}</textarea></div>
</div>
