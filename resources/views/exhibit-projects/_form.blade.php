@php($shows = \App\Models\ExhibitProject::SHOWS)
@php($statuses = \App\Models\ExhibitProject::STATUSES)
@php($types = \App\Models\ExhibitProject::PROJECT_TYPES)
@php($devices = \App\Models\ExhibitProject::REQUESTED_DEVICES)

<div class="form-group {{ $errors->has('show') ? 'has-error' : '' }}">
    <label for="show" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.show') }}</label>
    <div class="col-md-7">
        <select id="show" name="show" class="form-control select2">
            @foreach ($shows as $s)
                <option value="{{ $s }}" {{ old('show', $project->show ?? 'Grad Show') === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group {{ $errors->has('year') ? 'has-error' : '' }}">
    <label for="year" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.year') }}</label>
    <div class="col-md-7"><input type="number" id="year" name="year" class="form-control" value="{{ old('year', $project->year ?? date('Y')) }}" min="2000" max="2100"></div>
</div>

@include('partials.forms.edit.user-select', [
    'fieldname' => 'user_id',
    'translated_name' => trans('admin/exhibit-projects/general.student'),
    'item' => $project ?? null,
])

<div class="form-group">
    <label for="student_name" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.student_name') }}</label>
    <div class="col-md-7"><input type="text" id="student_name" name="student_name" class="form-control" value="{{ old('student_name', $project->student_name ?? '') }}"></div>
</div>

@include('partials.forms.edit.asset-select', [
    'fieldname' => 'asset_id',
    'translated_name' => trans('admin/exhibit-projects/general.asset'),
    'item' => $project ?? null,
])

<div class="form-group {{ $errors->has('status') ? 'has-error' : '' }}">
    <label for="status" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.status') }}</label>
    <div class="col-md-7">
        <select id="status" name="status" class="form-control select2">
            @foreach ($statuses as $status)
                <option value="{{ $status }}" {{ old('status', $project->status ?? 'pending') === $status ? 'selected' : '' }}>
                    {{ trans('admin/exhibit-projects/general.status_value_'.$status) }}
                </option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="project_type" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.project_type') }}</label>
    <div class="col-md-7">
        <select id="project_type" name="project_type" class="form-control select2">
            <option value="">—</option>
            @foreach ($types as $type)
                <option value="{{ $type }}" {{ old('project_type', $project->project_type ?? '') === $type ? 'selected' : '' }}>
                    {{ trans('admin/exhibit-projects/general.type_value_'.$type) }}
                </option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="requested_device" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.requested_device') }}</label>
    <div class="col-md-7">
        <input type="text" id="requested_device" name="requested_device" class="form-control" list="requested_device_options" value="{{ old('requested_device', $project->requested_device ?? '') }}">
        <datalist id="requested_device_options">
            @foreach ($devices as $device)
                <option value="{{ $device }}">
            @endforeach
        </datalist>
    </div>
</div>

<div class="form-group">
    <label for="peripherals" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.peripherals') }}</label>
    <div class="col-md-7"><input type="text" id="peripherals" name="peripherals" class="form-control" value="{{ old('peripherals', $project->peripherals ?? '') }}"></div>
</div>

<div class="form-group">
    <label for="project_details" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.project_details') }}</label>
    <div class="col-md-7"><textarea id="project_details" name="project_details" rows="2" class="form-control">{{ old('project_details', $project->project_details ?? '') }}</textarea></div>
</div>

<div class="form-group">
    <label class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.submitted_file') }}</label>
    <div class="col-md-7">
        <label class="checkbox-inline">
            <input type="checkbox" name="submitted_file" value="1" {{ old('submitted_file', $project->submitted_file ?? false) ? 'checked' : '' }}>
        </label>
    </div>
</div>

<div class="form-group">
    <label class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.approved') }}</label>
    <div class="col-md-7">
        <label class="checkbox-inline">
            <input type="checkbox" name="approved" value="1" {{ old('approved', $project->approved ?? false) ? 'checked' : '' }}>
        </label>
    </div>
</div>

<div class="form-group">
    <label for="tdx_id" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.tdx_id') }}</label>
    <div class="col-md-7"><input type="text" id="tdx_id" name="tdx_id" class="form-control" value="{{ old('tdx_id', $project->tdx_id ?? '') }}"></div>
</div>

<div class="form-group">
    <label for="notes" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.notes') }}</label>
    <div class="col-md-7"><textarea id="notes" name="notes" rows="3" class="form-control">{{ old('notes', $project->notes ?? '') }}</textarea></div>
</div>
