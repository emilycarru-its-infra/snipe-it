@extends('layouts/edit-form', [
    'createText' => trans('admin/suppliers/table.create') ,
    'updateText' => trans('admin/suppliers/table.update'),
    'helpTitle' => trans('admin/suppliers/table.about_suppliers_title'),
    'helpText' => trans('admin/suppliers/table.about_suppliers_text'),
    'formAction' => (isset($item->id)) ? route('suppliers.update', ['supplier' => $item->id]) : route('suppliers.store'),
])


{{-- Page content --}}
@section('inputFields')

@include ('partials.forms.edit.name', ['translated_name' => trans('admin/suppliers/table.name')])
@include ('partials.forms.edit.address')

{{-- Colleague's own vendor identifier, needed to key a purchase order
     against the right vendor. Distinct from this record's id. --}}
<div class="form-group {{ $errors->has('colleague_vendor_id') ? ' has-error' : '' }}">
    <label for="colleague_vendor_id" class="col-md-3 control-label">{{ trans('admin/purchase-orders/general.colleague_vendor_id') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="colleague_vendor_id" type="text" id="colleague_vendor_id" value="{{ old('colleague_vendor_id', $item->colleague_vendor_id) }}" placeholder="0135495">
        {!! $errors->first('colleague_vendor_id', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<div class="form-group {{ $errors->has('contact') ? ' has-error' : '' }}">
    <label for="contact" class="col-md-3 control-label">{{ trans('admin/suppliers/table.contact') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="contact" type="text" id="contact" value="{{ old('contact', $item->contact) }}">
        {!! $errors->first('contact', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

@include ('partials.forms.edit.phone')
@include ('partials.forms.edit.fax')
@include ('partials.forms.edit.email')

{{-- Extra addresses this supplier is reached at, beyond the single account
     contact above. Kept apart per purpose so a lessor's buyout request can
     never pick up an address belonging to a different lessor or to ordering. --}}
<div class="form-group {{ $errors->has('order_emails') ? ' has-error' : '' }}">
    <label for="order_emails" class="col-md-3 control-label">{{ trans('admin/suppliers/table.order_emails') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="order_emails" type="text" id="order_emails" value="{{ old('order_emails', $item->order_emails) }}" maxlength="191">
        <p class="help-block">{{ trans('admin/suppliers/table.order_emails_help') }}</p>
        {!! $errors->first('order_emails', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<div class="form-group {{ $errors->has('lease_emails') ? ' has-error' : '' }}">
    <label for="lease_emails" class="col-md-3 control-label">{{ trans('admin/suppliers/table.lease_emails') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="lease_emails" type="text" id="lease_emails" value="{{ old('lease_emails', $item->lease_emails) }}" maxlength="191">
        <p class="help-block">{{ trans('admin/suppliers/table.lease_emails_help') }}</p>
        {!! $errors->first('lease_emails', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

<div class="form-group {{ $errors->has('url') ? ' has-error' : '' }}">
    <label for="url" class="col-md-3 control-label">{{ trans('general.url') }}</label>
    <div class="col-md-7">
        <input class="form-control" name="url" type="url" id="url" value="{{ old('url', $item->url) }}">
        {!! $errors->first('url', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
    </div>
</div>

@include ('partials.forms.edit.notes')
@include ('partials.forms.edit.image-upload', ['image_path' => app('suppliers_upload_path')])

<fieldset name="color-preferences">
    <x-form.legend help_text="{{ trans('general.tag_color_help') }}">
        {{ trans('general.tag_color') }}
    </x-form.legend>
    <!--  color -->
    <div class="form-group {{ $errors->has('tag_color') ? 'error' : '' }}">
        <label for="tag_color" class="col-md-3 control-label">
            {{ trans('general.tag_color') }}
        </label>
        <div class="col-md-9">
            <x-input.colorpicker :item="$item" id="color" :value="old('color', ($item->color ?? '#f4f4f4'))" name="tag_color" id="tag_color" />
            {!! $errors->first('tag_color', '<span class="alert-msg" aria-hidden="true">:message</span>') !!}
        </div>
    </div>
</fieldset>

@stop
