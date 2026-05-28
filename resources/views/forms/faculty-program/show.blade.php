@extends('layouts/default')

@section('title')
    {{ trans('admin/forms/faculty-program.title') }}
    @parent
@stop

@section('content')

<div class="row">
    <div class="col-md-8 col-md-offset-2">

        <h1 style="margin-top:0;">{{ trans('admin/forms/faculty-program.title') }}</h1>
        <p class="text-muted">{{ trans('admin/forms/faculty-program.intro') }}</p>

        @if ($existingPickup)
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                {{ trans('admin/forms/faculty-program.existing_warning') }}
            </div>
        @endif

        <form method="POST" action="{{ route('forms.submit', 'faculty-program') }}" class="form-horizontal" autocomplete="off">
            @csrf

            {{-- Section 1: Payment --}}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/forms/faculty-program.section_payment') }}</h2>
                </div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/forms/faculty-program.payment_help') }}</p>
                    <div class="form-group {{ $errors->has('payment_method') ? 'has-error' : '' }}" style="margin-bottom:0;">
                        <div class="col-md-12">
                            <label class="radio-inline" style="margin-right:24px;">
                                <input type="radio" name="payment_method" value="pay_in_full" {{ old('payment_method') === 'pay_in_full' ? 'checked' : '' }} required>
                                {{ trans('admin/forms/faculty-program.payment_pay_in_full') }}
                            </label>
                            <label class="radio-inline">
                                <input type="radio" name="payment_method" value="payroll_deduction" {{ old('payment_method') === 'payroll_deduction' ? 'checked' : '' }}>
                                {{ trans('admin/forms/faculty-program.payment_payroll_deduction') }}
                            </label>
                            @if ($errors->has('payment_method'))
                                <p class="help-block">{{ $errors->first('payment_method') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Section 2: Buyout --}}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/forms/faculty-program.section_buyout') }}</h2>
                </div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/forms/faculty-program.buyout_help') }}</p>

                    <div class="form-group {{ $errors->has('buyout_decision') ? 'has-error' : '' }}">
                        <div class="col-md-12">
                            <div class="radio">
                                <label>
                                    <input type="radio" name="buyout_decision" value="yes" {{ old('buyout_decision') === 'yes' ? 'checked' : '' }} required>
                                    {{ trans('admin/forms/faculty-program.buyout_yes') }}
                                </label>
                            </div>
                            <div class="radio">
                                <label>
                                    <input type="radio" name="buyout_decision" value="no" {{ old('buyout_decision') === 'no' ? 'checked' : '' }}>
                                    {{ trans('admin/forms/faculty-program.buyout_no') }}
                                </label>
                            </div>
                            <div class="radio">
                                <label>
                                    <input type="radio" name="buyout_decision" value="no_prior_laptop" {{ old('buyout_decision') === 'no_prior_laptop' ? 'checked' : '' }}>
                                    {{ trans('admin/forms/faculty-program.buyout_no_prior_laptop') }}
                                </label>
                            </div>
                            @if ($errors->has('buyout_decision'))
                                <p class="help-block">{{ $errors->first('buyout_decision') }}</p>
                            @endif
                        </div>
                    </div>

                    @if ($priorAsset)
                        <div class="alert alert-info" style="margin-bottom:0;">
                            <strong>{{ trans('admin/forms/faculty-program.buyout_prior_asset') }}:</strong>
                            {{ $priorAsset->asset_tag }} &middot; {{ $priorAsset->serial }} &middot; {{ $priorAsset->model?->name }}
                        </div>
                    @endif

                    <div class="form-group" style="margin-top:10px;">
                        <label for="buyout_asset_tag" class="col-md-3 control-label">{{ trans('admin/forms/faculty-program.buyout_asset_tag') }}</label>
                        <div class="col-md-4">
                            <input type="text" id="buyout_asset_tag" name="buyout_asset_tag" class="form-control" maxlength="191"
                                   value="{{ old('buyout_asset_tag', $priorAsset?->asset_tag) }}">
                        </div>
                        <label for="buyout_serial" class="col-md-2 control-label">{{ trans('admin/forms/faculty-program.buyout_serial') }}</label>
                        <div class="col-md-3">
                            <input type="text" id="buyout_serial" name="buyout_serial" class="form-control" maxlength="191"
                                   value="{{ old('buyout_serial', $priorAsset?->serial) }}">
                        </div>
                    </div>
                    @if (! $priorAsset)
                        <p class="text-muted col-md-offset-3" style="font-size:12px;">{{ trans('admin/forms/faculty-program.buyout_no_match') }}</p>
                    @endif
                </div>
            </div>

            {{-- Section 3: Notes --}}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/forms/faculty-program.section_notes') }}</h2>
                </div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/forms/faculty-program.notes_help') }}</p>
                    <div class="form-group" style="margin-bottom:0;">
                        <div class="col-md-12">
                            <textarea name="notes" class="form-control" rows="4" maxlength="65535">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Section 4: Terms --}}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/forms/faculty-program.section_terms') }}</h2>
                </div>
                <div class="box-body">
                    <p>{{ trans('admin/forms/faculty-program.terms_intro') }}</p>
                    <div class="well well-sm" style="white-space:pre-line;">{{ trans('admin/forms/faculty-program.terms_body') }}</div>
                    <div class="form-group {{ $errors->has('accept_terms') ? 'has-error' : '' }}" style="margin-bottom:0;">
                        <div class="col-md-12">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="accept_terms" value="1" {{ old('accept_terms') ? 'checked' : '' }} required>
                                <strong>{{ trans('admin/forms/faculty-program.terms_accept') }}</strong>
                            </label>
                            @if ($errors->has('accept_terms'))
                                <p class="help-block">{{ $errors->first('accept_terms') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <div class="col-md-12 text-right">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i>
                        {{ trans('admin/forms/faculty-program.submit') }}
                    </button>
                </div>
            </div>
        </form>

    </div>
</div>

@stop
