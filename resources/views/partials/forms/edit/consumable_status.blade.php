<!-- Consumable lifecycle status -->
<div class="form-group {{ $errors->has('status') ? ' has-error' : '' }}">
    <label for="status" class="col-md-3 control-label">{{ trans('admin/consumables/general.status') }}</label>
    <div class="col-md-7 col-sm-12">
        @php
            $current_status = old('status', isset($item) ? $item->status : 'active') ?: 'active';
        @endphp
        <select class="form-control" name="status" id="status" aria-label="status">
            @foreach (\App\Models\Consumable::STATUSES as $status_option)
                <option value="{{ $status_option }}" {{ $current_status === $status_option ? 'selected' : '' }}>
                    {{ trans('admin/consumables/general.status_'.$status_option) }}
                </option>
            @endforeach
        </select>
        <p class="help-block">{{ trans('admin/consumables/general.status_help') }}</p>
        {!! $errors->first('status', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>
