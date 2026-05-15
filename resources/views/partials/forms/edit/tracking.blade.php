<!-- Tracking Number -->
<div class="form-group {{ $errors->has('tracking_number') ? ' has-error' : '' }}">
   <label for="tracking_number" class="col-md-3 control-label">{{ trans('general.tracking_number') }}</label>
   <div class="col-md-7 col-sm-12">
       <input class="form-control" type="text" name="tracking_number" aria-label="tracking_number" id="tracking_number" value="{{ old('tracking_number', $item->tracking_number) }}" maxlength="191" />
       {!! $errors->first('tracking_number', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
   </div>
</div>

<!-- Shipping Carrier -->
<div class="form-group {{ $errors->has('tracking_carrier') ? ' has-error' : '' }}">
   <label for="tracking_carrier" class="col-md-3 control-label">{{ trans('general.tracking_carrier') }}</label>
   <div class="col-md-7 col-sm-12">
       <input class="form-control" type="text" name="tracking_carrier" aria-label="tracking_carrier" id="tracking_carrier" value="{{ old('tracking_carrier', $item->tracking_carrier) }}" maxlength="191" />
       {!! $errors->first('tracking_carrier', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
   </div>
</div>
