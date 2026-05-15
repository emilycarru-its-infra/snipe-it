<!-- Maintenance contract -->
<div class="form-group {{ $errors->has('on_maintenance_contract') ? ' has-error' : '' }}">
    <div class="col-sm-offset-3 col-md-9">
        <label class="form-control" for="on_maintenance_contract">
            <input type="checkbox" value="1" name="on_maintenance_contract" id="on_maintenance_contract"
                {{ old('on_maintenance_contract', isset($item) ? $item->on_maintenance_contract : false) ? 'checked="checked"' : '' }}>
            {{ trans('admin/consumables/general.on_maintenance_contract') }}
        </label>
        <p class="help-block">{{ trans('admin/consumables/general.on_maintenance_contract_help') }}</p>
        {!! $errors->first('on_maintenance_contract', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>
