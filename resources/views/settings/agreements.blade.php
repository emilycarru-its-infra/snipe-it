@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/settings/general.agreements_title') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('settings.index') }}" class="btn btn-primary"> {{ trans('general.back') }}</a>
@stop

{{-- Page content --}}
@section('content')

    @php
        $agreementSections = [
            'pickup' => trans('admin/settings/general.agreements_pickup_heading'),
            'upgrade' => trans('admin/settings/general.agreements_upgrade_heading'),
            'purchase' => trans('admin/settings/general.agreements_purchase_heading'),
        ];
    @endphp

    <form method="POST" action="{{ route('settings.agreements.save') }}" autocomplete="off" class="form-horizontal" role="form" id="create-form">

    <!-- CSRF Token -->
    {{ csrf_field() }}

    <div class="row">
        <div class="col-sm-10 col-sm-offset-1 col-md-8 col-md-offset-2">

            <div class="panel box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">
                        <x-icon type="eula"/> {{ trans('admin/settings/general.agreements') }}
                    </h2>
                </div>
                <div class="box-body">

                    <div class="col-md-12">

                        <div class="alert alert-info">
                            {{ trans('admin/settings/general.agreements_intro') }}
                        </div>
                        <p class="help-block">{!! trans('admin/settings/general.agreements_merge_vars') !!}</p>

                        @foreach ($agreementSections as $type => $heading)
                            <fieldset name="agreement-{{ $type }}">
                                <x-form.legend>{{ $heading }}</x-form.legend>

                                <!-- Title -->
                                <div class="form-group {{ $errors->has('agreement_'.$type.'_title') ? 'error' : '' }}">
                                    <label for="agreement_{{ $type }}_title" class="col-md-3 control-label">{{ trans('admin/settings/general.agreements_field_title') }}</label>
                                    <div class="col-md-9">
                                        <input
                                            type="text"
                                            name="agreement_{{ $type }}_title"
                                            id="agreement_{{ $type }}_title"
                                            class="form-control"
                                            value="{{ old('agreement_'.$type.'_title', $setting->{'agreement_'.$type.'_title'}) }}"
                                            placeholder="{{ trans('admin/user-agreements/eula.'.$type.'_title') }}"
                                        >
                                        {!! $errors->first('agreement_'.$type.'_title', '<span class="alert-msg" aria-hidden="true">:message</span>') !!}
                                    </div>
                                </div>

                                <!-- Body -->
                                <div class="form-group {{ $errors->has('agreement_'.$type.'_body') ? 'error' : '' }}">
                                    <label for="agreement_{{ $type }}_body" class="col-md-3 control-label">{{ trans('admin/settings/general.agreements_field_body') }}</label>
                                    <div class="col-md-9">
                                        <x-input.textarea
                                            name="agreement_{{ $type }}_body"
                                            id="agreement_{{ $type }}_body"
                                            :value="old('agreement_'.$type.'_body', $setting->{'agreement_'.$type.'_body'})"
                                            :rows="14"
                                            placeholder="{{ trans('admin/user-agreements/eula.'.$type.'_body') }}"
                                            style="font-family: var(--bs-font-monospace, monospace); font-size: 12px;"
                                        />
                                        {!! $errors->first('agreement_'.$type.'_body', '<span class="alert-msg" aria-hidden="true">:message</span>') !!}
                                        <p class="help-block">{{ trans('admin/settings/general.agreements_blank_help') }}</p>
                                    </div>
                                </div>
                            </fieldset>
                        @endforeach

                    </div>

                </div> <!--/.box-body-->
                <div class="box-footer">
                    <div class="text-left col-md-6">
                        <a class="btn btn-link text-left" href="{{ route('settings.index') }}">{{ trans('button.cancel') }}</a>
                    </div>
                    <div class="text-right col-md-6">
                        <button type="submit" class="btn btn-primary"><x-icon type="checkmark" /> {{ trans('general.save') }}</button>
                    </div>
                </div>
            </div> <!-- /box -->
        </div> <!-- /.col-md-8-->
    </div> <!-- /.row-->

    </form>

@stop
