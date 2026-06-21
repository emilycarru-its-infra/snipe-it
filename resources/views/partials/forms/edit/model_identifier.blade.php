<!-- Model Identifier -->
<div class="form-group {{ $errors->has('model_identifier') ? ' has-error' : '' }}">
    <label for="model_identifier" class="col-md-3 control-label">{{ trans('general.model_identifier') }}</label>
    <div class="col-md-7">
    <input class="form-control" type="text" name="model_identifier" aria-label="model_identifier" id="model_identifier" value="{{ old('model_identifier', $item->model_identifier) }}" />
        {!! $errors->first('model_identifier', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>
