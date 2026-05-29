@extends('layouts/default')

@section('title')
    {{ trans('admin/forms/faculty-program.title') }}
    @parent
@stop

@push('css')
<style>
    .forms-radio-group { display: flex; flex-wrap: wrap; gap: 16px 24px; }
    .forms-radio-group--stacked { flex-direction: column; gap: 12px; }
    .forms-radio { display: inline-flex; align-items: center; gap: 12px; font-weight: normal; margin: 0; cursor: pointer; }
    .forms-radio input[type="radio"], .forms-radio input[type="checkbox"] { flex: 0 0 auto; margin: 0; }
    .forms-radio span { line-height: 1.4; }
</style>
@endpush

@section('content')

<div class="row">
    <div class="col-md-8">

        <h1 style="margin-top:0;">{{ trans('admin/forms/faculty-program.title') }}</h1>
        <p class="lead" style="font-size:20px; color:#333; margin-bottom:24px;">{{ trans('admin/forms/faculty-program.intro') }}</p>

        @if ($existingPickup)
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                {{ trans('admin/forms/faculty-program.existing_warning') }}
            </div>
        @endif

        {{-- Choosing your model (informational, pre-form) --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('admin/forms/faculty-program.section_choose_model') }}</h2>
            </div>
            <div class="box-body">
                <p>{{ trans('admin/forms/faculty-program.choose_model_intro') }}</p>
                <p style="margin-bottom:6px;"><strong>{{ trans('admin/forms/faculty-program.choose_model_help_label') }}</strong></p>
                <ul style="margin-bottom:12px;">
                    <li>{{ trans('admin/forms/faculty-program.choose_model_air_13') }}</li>
                    <li>{{ trans('admin/forms/faculty-program.choose_model_air_15') }}</li>
                    <li>{{ trans('admin/forms/faculty-program.choose_model_pro_14') }}</li>
                    <li>{{ trans('admin/forms/faculty-program.choose_model_pro_max') }}</li>
                </ul>
                <p style="margin-bottom:0;">
                    {{ trans('admin/forms/faculty-program.choose_model_compare_intro') }}
                    <a href="{{ trans('admin/forms/faculty-program.choose_model_compare_url') }}" target="_blank" rel="noopener">
                        {{ trans('admin/forms/faculty-program.choose_model_compare_label') }}
                        <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                    </a>
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('forms.submit', 'faculty-program') }}" class="form-horizontal" autocomplete="off">
            @csrf

            {{-- Section 1: Top-up acknowledgment --}}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/forms/faculty-program.section_top_up') }}</h2>
                </div>
                <div class="box-body">
                    <p>{!! trans('admin/forms/faculty-program.top_up_help_html') !!}</p>
                    <div class="form-group {{ $errors->has('acknowledge_top_up') ? 'has-error' : '' }}" style="margin-bottom:0;">
                        <div class="col-md-12 forms-radio-group">
                            <label class="forms-radio">
                                <input type="checkbox" name="acknowledge_top_up" value="1" {{ old('acknowledge_top_up') ? 'checked' : '' }} required>
                                <span><strong>{{ trans('admin/forms/faculty-program.top_up_acknowledge') }}</strong></span>
                            </label>
                            @if ($errors->has('acknowledge_top_up'))
                                <p class="help-block">{{ $errors->first('acknowledge_top_up') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Section 2: Payment method --}}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/forms/faculty-program.section_payment') }}</h2>
                </div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/forms/faculty-program.payment_help') }}</p>
                    <div class="form-group {{ $errors->has('payment_method') ? 'has-error' : '' }}" style="margin-bottom:0;">
                        <div class="col-md-12 forms-radio-group">
                            <label class="forms-radio">
                                <input type="radio" name="payment_method" value="pay_in_full" {{ old('payment_method') === 'pay_in_full' ? 'checked' : '' }} required>
                                <span>{{ trans('admin/forms/faculty-program.payment_pay_in_full') }}</span>
                            </label>
                            <label class="forms-radio">
                                <input type="radio" name="payment_method" value="payroll_deduction" {{ old('payment_method') === 'payroll_deduction' ? 'checked' : '' }}>
                                <span>{{ trans('admin/forms/faculty-program.payment_payroll_deduction') }}</span>
                            </label>
                            @if ($errors->has('payment_method'))
                                <p class="help-block">{{ $errors->first('payment_method') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Section 3: Buyout --}}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/forms/faculty-program.section_buyout') }}</h2>
                </div>
                <div class="box-body">
                    <p style="margin-bottom:6px;"><strong>{{ trans('admin/forms/faculty-program.buyout_cost_intro') }}</strong></p>
                    <ul style="margin-bottom:14px;">
                        <li>{{ trans('admin/forms/faculty-program.buyout_cost_air_13') }}</li>
                        <li>{{ trans('admin/forms/faculty-program.buyout_cost_pro_13') }}</li>
                        <li>{{ trans('admin/forms/faculty-program.buyout_cost_pro_15') }}</li>
                    </ul>

                    <div class="form-group {{ $errors->has('buyout_decision') ? 'has-error' : '' }}">
                        <div class="col-md-12 forms-radio-group forms-radio-group--stacked">
                            <label class="forms-radio">
                                <input type="radio" name="buyout_decision" value="yes" {{ old('buyout_decision') === 'yes' ? 'checked' : '' }} required>
                                <span>{{ trans('admin/forms/faculty-program.buyout_yes') }}</span>
                            </label>
                            <label class="forms-radio">
                                <input type="radio" name="buyout_decision" value="no" {{ old('buyout_decision') === 'no' ? 'checked' : '' }}>
                                <span>{{ trans('admin/forms/faculty-program.buyout_no') }}</span>
                            </label>
                            <label class="forms-radio">
                                <input type="radio" name="buyout_decision" value="no_prior_laptop" {{ old('buyout_decision') === 'no_prior_laptop' ? 'checked' : '' }}>
                                <span>{{ trans('admin/forms/faculty-program.buyout_no_prior_laptop') }}</span>
                            </label>
                            @if ($errors->has('buyout_decision'))
                                <p class="help-block">{{ $errors->first('buyout_decision') }}</p>
                            @endif
                        </div>
                    </div>

                    @if ($priorAsset)
                        <div class="alert alert-info" style="margin-bottom:0;">
                            <strong>{{ trans('admin/forms/faculty-program.buyout_prior_asset') }}:</strong>
                            {{ $priorAsset->asset_tag }} &middot; {{ $priorAsset->serial }} &middot; {{ $priorAsset->model?->name }}
                            @if (! is_null($priorBuyoutCost))
                                <br>
                                <strong>{{ trans('admin/forms/faculty-program.buyout_cost') }}:</strong>
                                ${{ \App\Helpers\Helper::formatCurrencyOutput($priorBuyoutCost) }}
                            @endif
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

            {{-- Section 4: Notes / questions for IT --}}
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

            {{-- Section 5: Terms --}}
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ trans('admin/forms/faculty-program.section_terms') }}</h2>
                </div>
                <div class="box-body">
                    <p>{{ trans('admin/forms/faculty-program.terms_intro') }}</p>
                    <div class="well well-sm" style="max-height:360px; overflow-y:auto;">
                        <h3 style="margin-top:0; margin-bottom:12px;">{{ trans('admin/forms/faculty-program.terms_heading') }}</h3>

                        <h4 style="margin-top:14px;">{{ trans('admin/forms/faculty-program.terms_return_title') }}</h4>
                        <p>{{ trans('admin/forms/faculty-program.terms_return_p1') }}</p>
                        <p>{{ trans('admin/forms/faculty-program.terms_return_p2') }}</p>

                        <h4 style="margin-top:14px;">{{ trans('admin/forms/faculty-program.terms_care_title') }}</h4>
                        <p>{{ trans('admin/forms/faculty-program.terms_care_intro') }}</p>
                        <ul>
                            <li>{{ trans('admin/forms/faculty-program.terms_care_normal_1') }}</li>
                            <li>{{ trans('admin/forms/faculty-program.terms_care_normal_2') }}</li>
                            <li>{{ trans('admin/forms/faculty-program.terms_care_normal_3') }}</li>
                            <li>{{ trans('admin/forms/faculty-program.terms_care_normal_4') }}</li>
                        </ul>
                        <p>{{ trans('admin/forms/faculty-program.terms_care_not_normal_intro') }}</p>
                        <ul>
                            <li>{{ trans('admin/forms/faculty-program.terms_care_not_normal_1') }}</li>
                            <li>{{ trans('admin/forms/faculty-program.terms_care_not_normal_2') }}</li>
                            <li>{{ trans('admin/forms/faculty-program.terms_care_not_normal_3') }}</li>
                            <li>{{ trans('admin/forms/faculty-program.terms_care_not_normal_4') }}</li>
                            <li>{{ trans('admin/forms/faculty-program.terms_care_not_normal_5') }}</li>
                        </ul>

                        <h4 style="margin-top:14px;">{{ trans('admin/forms/faculty-program.terms_physical_security_title') }}</h4>
                        <p>{{ trans('admin/forms/faculty-program.terms_physical_security_body') }}</p>

                        <h4 style="margin-top:14px;">{{ trans('admin/forms/faculty-program.terms_data_security_title') }}</h4>
                        <p>{{ trans('admin/forms/faculty-program.terms_data_security_body') }}</p>

                        <h4 style="margin-top:14px;">{{ trans('admin/forms/faculty-program.terms_security_threats_title') }}</h4>
                        <p>{{ trans('admin/forms/faculty-program.terms_security_threats_body') }}</p>

                        <h4 style="margin-top:14px;">{{ trans('admin/forms/faculty-program.terms_software_title') }}</h4>
                        <p>{{ trans('admin/forms/faculty-program.terms_software_body') }}</p>

                        <h4 style="margin-top:14px;">{{ trans('admin/forms/faculty-program.terms_top_up_title') }}</h4>
                        <p style="margin-bottom:0;">{{ trans('admin/forms/faculty-program.terms_top_up_body') }}</p>
                    </div>
                    <div class="form-group {{ $errors->has('accept_terms') ? 'has-error' : '' }}" style="margin-bottom:0; margin-top:14px;">
                        <div class="col-md-12 forms-radio-group">
                            <label class="forms-radio">
                                <input type="checkbox" name="accept_terms" value="1" {{ old('accept_terms') ? 'checked' : '' }} required>
                                <span><strong>{{ trans('admin/forms/faculty-program.terms_accept') }}</strong></span>
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
