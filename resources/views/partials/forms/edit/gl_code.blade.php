<!-- GL Code -->
<div class="form-group {{ $errors->has('gl_code') ? ' has-error' : '' }}">
   <label for="gl_code" class="col-md-3 control-label">{{ trans('admin/hardware/form.gl_code') }}</label>
   <div class="col-md-7 col-sm-12">
       <input class="form-control" type="text" name="gl_code" aria-label="gl_code" id="gl_code" value="{{ old('gl_code', $item->gl_code) }}" maxlength="191" />
       {!! $errors->first('gl_code', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
   </div>
</div>
