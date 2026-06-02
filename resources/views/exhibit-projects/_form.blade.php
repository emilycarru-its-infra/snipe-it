@php($exhibits = \App\Models\Exhibit::where('active', true)->orderBy('sort_order')->orderBy('name')->get())
@php($statuses = \App\Models\ExhibitStatus::where('active', true)->orderBy('sort_order')->orderBy('name')->get())
@php($types = \App\Models\ExhibitProjectType::where('active', true)->orderBy('sort_order')->orderBy('name')->get())
@php($devices = \App\Models\ExhibitProject::REQUESTED_DEVICES)

<div class="form-group {{ $errors->has('exhibit_id') ? 'has-error' : '' }}">
    <label for="exhibit_id" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.show') }}</label>
    <div class="col-md-7">
        <select id="exhibit_id" name="exhibit_id" class="form-control select2">
            @foreach ($exhibits as $ex)
                <option value="{{ $ex->id }}" {{ (int) old('exhibit_id', $project->exhibit_id ?? 0) === (int) $ex->id ? 'selected' : '' }}>{{ $ex->name }}</option>
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

<div class="form-group {{ $errors->has('status_id') ? 'has-error' : '' }}">
    <label for="status_id" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.status') }}</label>
    <div class="col-md-7">
        <select id="status_id" name="status_id" class="form-control select2">
            @foreach ($statuses as $st)
                <option value="{{ $st->id }}" {{ (int) old('status_id', $project->status_id ?? 0) === (int) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="project_type_id" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.project_type') }}</label>
    <div class="col-md-7">
        <select id="project_type_id" name="project_type_id" class="form-control select2">
            <option value="">—</option>
            @foreach ($types as $t)
                <option value="{{ $t->id }}" {{ (int) old('project_type_id', $project->project_type_id ?? 0) === (int) $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
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
    <div class="col-md-7"><label class="checkbox-inline"><input type="checkbox" name="submitted_file" value="1" {{ old('submitted_file', $project->submitted_file ?? false) ? 'checked' : '' }}></label></div>
</div>

<div class="form-group">
    <label class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.approved') }}</label>
    <div class="col-md-7"><label class="checkbox-inline"><input type="checkbox" name="approved" value="1" {{ old('approved', $project->approved ?? false) ? 'checked' : '' }}></label></div>
</div>

<div class="form-group">
    <label for="tdx_id" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.tdx_id') }}</label>
    <div class="col-md-7"><input type="text" id="tdx_id" name="tdx_id" class="form-control" value="{{ old('tdx_id', $project->tdx_id ?? '') }}"></div>
</div>

<div class="form-group">
    <label for="notes" class="col-md-3 control-label">{{ trans('admin/exhibit-projects/general.notes') }}</label>
    <div class="col-md-7"><textarea id="notes" name="notes" rows="3" class="form-control">{{ old('notes', $project->notes ?? '') }}</textarea></div>
</div>
