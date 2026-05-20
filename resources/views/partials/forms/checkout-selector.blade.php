@php
    // Pages may override which target tab is selected when no session value
    // is set yet (e.g. consumable checkout defaults to Asset). Existing
    // callers omit $default_type and continue to default to User.
    $defaultType = $default_type ?? 'user';
    $effectiveType = session('checkout_to_type') ?: $defaultType;
@endphp
<div class="form-group" id="assignto_selector"{!!  (isset($style)) ? ' style="'.e($style).'"' : ''  !!}>
    <label for="checkout_to_type" class="col-md-3 control-label">{{ trans('admin/hardware/form.checkout_to') }}</label>
    <div class="col-md-8">

        <div class="btn-group" data-toggle="buttons">
            @if ((isset($user_select)) && ($user_select!='false'))
                <label class="btn btn-theme{{ $effectiveType == 'user' ? ' active' : '' }}">
                    <input name="checkout_to_type" value="user" aria-label="checkout_to_type"
                           type="radio" {{ $effectiveType == 'user' ? 'checked' : '' }}>
                <x-icon type="user" />
                {{ trans('general.user') }}
            </label>
            @endif
            @if ((isset($asset_select)) && ($asset_select!='false'))
                <label class="btn btn-theme{{ $effectiveType == 'asset' ? ' active' : '' }}">
                    <input name="checkout_to_type" value="asset" aria-label="checkout_to_type"
                           type="radio" {{ $effectiveType == 'asset' ? 'checked': '' }}>
                <i class="fas fa-barcode" aria-hidden="true"></i>
                {{ trans('general.asset') }}
            </label>
            @endif
            @if ((isset($location_select)) && ($location_select!='false'))
                <label class="btn btn-theme{{ $effectiveType == 'location' ? ' active' : '' }}">
                    <input name="checkout_to_type" value="location" aria-label="checkout_to_type"
                           type="radio" {{ $effectiveType == 'location' ? 'checked' : '' }}>
                <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                {{ trans('general.location') }}
            </label>
            @endif

            {!! $errors->first('checkout_to_type', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
        </div>
    </div>
</div>
