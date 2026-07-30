@extends('layouts/default')

@section('title')
    {{ trans('admin/forms/faculty-program.title') }}
    @parent
@stop

{{-- The program intake, styled like the store it leads into — because it is
     step one of the same journey, not a different system.

     The old laptop is presented as a trade-in: its card carries the photo,
     the tag and serial, the estimated buyout, and the keep-or-return choice
     right on it — the same visual grammar as the order status page, where
     that machine appears again on the left of the handover.

     Every control is drawn from scratch (appearance:none) so radios and
     checkboxes render identically in light and dark — the browser-default
     widgets under the admin theme's dark palette produced half-filled
     circles and invisible borders. --}}

@push('css')
<style>
.fp-wrap { max-width: 860px; }
.fp-lead { font-size: 17px; opacity: .75; margin: 4px 0 22px; }
.fp-card { border: 1px solid light-dark(#e2e2e6, #3a3a3e); border-radius: 14px;
    background: light-dark(#fff, #1f2023); padding: 22px 24px; margin-bottom: 16px; }
.fp-kicker { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; opacity: .55; margin-bottom: 4px; }
.fp-card h2 { font-size: 19px; font-weight: 700; margin: 0 0 10px; }
.fp-card p { line-height: 1.55; }
.fp-muted { opacity: .7; font-size: 13px; }

.fp-tradein { display: flex; gap: 20px; flex-wrap: wrap; }
.fp-tradein-machine { flex: 0 1 250px; text-align: center; }
.fp-tradein-machine img { max-height: 110px; max-width: 100%; object-fit: contain; margin: 8px auto; display: block; }
.fp-tradein-choice { flex: 1 1 320px; }
.fp-machines { flex: 0 1 290px; }
.fp-machine img { max-height: 44px; max-width: 64px; object-fit: contain; }
.fp-machine-facts { font-size: 12px; opacity: .7; }
.fp-tag { font-family: ui-monospace, Menlo, monospace; font-weight: 700; }
.fp-facts { font-size: 13px; margin: 8px auto 0; display: inline-block; text-align: left; }
.fp-facts td { padding: 2px 8px 2px 0; }
.fp-facts td:first-child { opacity: .6; }
.fp-buyout-price { font-size: 22px; font-weight: 700; margin-top: 8px; }

.fp-opt { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; margin: 0 0 8px;
    border: 1px solid light-dark(#d9d9de, #44444a); border-radius: 10px; cursor: pointer;
    font-weight: 400; transition: border-color .12s ease; background: light-dark(#fafafc, #26272b); }
.fp-opt:hover { border-color: light-dark(#9a9aa2, #6a6a72); }
.fp-opt.fp-selected { border-color: #00a65a; box-shadow: 0 0 0 1px #00a65a inset; }
.fp-opt input { position: absolute; opacity: 0; pointer-events: none; }
.fp-ctl { flex: 0 0 20px; height: 20px; width: 20px; margin-top: 1px; border: 2px solid light-dark(#9a9aa2, #7a7a82);
    background: light-dark(#fff, #1a1b1e); position: relative; }
.fp-opt input[type="radio"] + .fp-ctl { border-radius: 50%; }
.fp-opt input[type="checkbox"] + .fp-ctl { border-radius: 5px; }
.fp-opt input:checked + .fp-ctl { border-color: #00a65a; background: #00a65a; }
.fp-opt input[type="radio"]:checked + .fp-ctl::after { content: ''; position: absolute; inset: 4px;
    border-radius: 50%; background: #fff; }
.fp-opt input[type="checkbox"]:checked + .fp-ctl::after { content: ''; position: absolute; left: 5px; top: 1px;
    width: 6px; height: 11px; border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg); }
.fp-opt input:focus-visible + .fp-ctl { outline: 2px solid light-dark(#3c8dbc, #6cb2e0); outline-offset: 2px; }
.fp-opt-text { line-height: 1.45; }

.fp-inline-fields { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 10px; }
.fp-inline-fields label { display: block; font-size: 12px; opacity: .65; margin-bottom: 3px; font-weight: 600; }
.fp-inline-fields input { font-family: ui-monospace, Menlo, monospace; }

.fp-terms { max-height: 320px; overflow-y: auto; border: 1px solid light-dark(#e2e2e6, #3a3a3e);
    border-radius: 10px; padding: 14px 18px; background: light-dark(#fafafc, #191a1d); font-size: 13px; }
.fp-terms h4 { font-size: 14px; margin: 14px 0 6px; }
.fp-terms h3 { font-size: 16px; margin: 0 0 10px; }

.fp-error { color: #dd4b39; font-size: 12px; margin: 4px 0 0; }
.fp-submit { margin: 22px 0 40px; text-align: right; }
</style>
@endpush

@section('content')

<div class="fp-wrap">

    <h1 style="margin-top:0;">{{ trans('admin/forms/faculty-program.title') }}</h1>
    <p class="fp-lead">{{ trans('admin/forms/faculty-program.intro') }}</p>

    @if ($existingPickup)
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            {{ trans('admin/forms/faculty-program.existing_warning') }}
        </div>
    @endif

    <form method="POST" action="{{ route('forms.submit', 'faculty-program') }}" autocomplete="off">
        @csrf

        {{-- The trade-in: their current laptop, or the statement that this
             is their first. The keep-or-return decision lives on the card
             with the machine it is about. --}}
        <div class="fp-card">
            <div class="fp-kicker">{{ trans('admin/forms/faculty-program.tradein_kicker') }}</div>
            <h2>{{ $priorAsset
                ? trans('admin/forms/faculty-program.tradein_title')
                : trans('admin/forms/faculty-program.tradein_none_title') }}</h2>

            <div class="fp-tradein">
                {{-- One laptop: shown as fact. Several: they say which one
                     this renewal is about, and that pick follows the order
                     everywhere — the handover page, the -LE rename, the
                     buyout paperwork. --}}
                @if ($laptops->count() > 1)
                    <div class="fp-machines">
                        @foreach ($laptops as $laptop)
                            @php
                                $machineChecked = (int) old('returning_asset_id', $priorAsset?->id) === $laptop->id;
                            @endphp
                            <label class="fp-opt {{ $machineChecked ? 'fp-selected' : '' }}">
                                <input type="radio" name="returning_asset_id" value="{{ $laptop->id }}"
                                       data-tag="{{ $laptop->asset_tag }}" data-serial="{{ $laptop->serial }}"
                                       {{ $machineChecked ? 'checked' : '' }}>
                                <span class="fp-ctl"></span>
                                <span class="fp-opt-text">
                                    <strong>{{ $laptop->model?->name }}</strong><br>
                                    <span class="fp-machine-facts">
                                        <span class="fp-tag">{{ $laptop->asset_tag }}</span>
                                        @if ($laptop->serial) · <span class="fp-tag">{{ $laptop->serial }}</span> @endif
                                        @if (! is_null($buyoutCosts->get($laptop->id)))
                                            <br>{{ trans('admin/forms/faculty-program.tradein_buyout_estimate') }}
                                            <strong>${{ \App\Helpers\Helper::formatCurrencyOutput($buyoutCosts->get($laptop->id)) }}</strong>
                                        @endif
                                    </span>
                                </span>
                                @if ($laptop->getImageUrl())
                                    <img src="{{ $laptop->getImageUrl() }}" alt="" style="margin-left:auto;">
                                @endif
                            </label>
                        @endforeach
                    </div>
                @elseif ($priorAsset)
                    <div class="fp-tradein-machine">
                        <input type="hidden" name="returning_asset_id" value="{{ $priorAsset->id }}">
                        @if ($priorAsset->getImageUrl())
                            <img src="{{ $priorAsset->getImageUrl() }}" alt="">
                        @else
                            <i class="fa-solid fa-laptop" style="font-size:54px; opacity:.25; margin:14px 0;"></i>
                        @endif
                        <div><strong>{{ $priorAsset->model?->name }}</strong></div>
                        <table class="fp-facts">
                            <tr><td>{{ trans('mail.asset_tag') }}</td><td class="fp-tag">{{ $priorAsset->asset_tag }}</td></tr>
                            <tr><td>{{ trans('mail.serial') }}</td><td class="fp-tag">{{ $priorAsset->serial }}</td></tr>
                        </table>
                        @if (! is_null($buyoutCosts->get($priorAsset->id)))
                            <div class="fp-buyout-price">${{ \App\Helpers\Helper::formatCurrencyOutput($buyoutCosts->get($priorAsset->id)) }}</div>
                            <div class="fp-muted">{{ trans('admin/forms/faculty-program.tradein_buyout_estimate') }}</div>
                        @endif
                    </div>
                @endif

                <div class="fp-tradein-choice">
                    @if (! $priorAsset)
                        <p class="fp-muted" style="margin-top:0;">{{ trans('admin/forms/faculty-program.tradein_none_sub') }}</p>
                    @endif

                    <label class="fp-opt {{ old('buyout_decision') === 'yes' ? 'fp-selected' : '' }}">
                        <input type="radio" name="buyout_decision" value="yes" {{ old('buyout_decision') === 'yes' ? 'checked' : '' }} required>
                        <span class="fp-ctl"></span>
                        <span class="fp-opt-text">{{ trans('admin/forms/faculty-program.buyout_yes') }}</span>
                    </label>
                    <label class="fp-opt {{ old('buyout_decision') === 'no' ? 'fp-selected' : '' }}">
                        <input type="radio" name="buyout_decision" value="no" {{ old('buyout_decision') === 'no' ? 'checked' : '' }}>
                        <span class="fp-ctl"></span>
                        <span class="fp-opt-text">{{ trans('admin/forms/faculty-program.buyout_no') }}</span>
                    </label>
                    <label class="fp-opt {{ old('buyout_decision', $priorAsset ? null : 'no_prior_laptop') === 'no_prior_laptop' ? 'fp-selected' : '' }}">
                        <input type="radio" name="buyout_decision" value="no_prior_laptop"
                               {{ old('buyout_decision', $priorAsset ? null : 'no_prior_laptop') === 'no_prior_laptop' ? 'checked' : '' }}>
                        <span class="fp-ctl"></span>
                        <span class="fp-opt-text">{{ trans('admin/forms/faculty-program.buyout_no_prior_laptop') }}</span>
                    </label>
                    @if ($errors->has('buyout_decision'))
                        <p class="fp-error">{{ $errors->first('buyout_decision') }}</p>
                    @endif

                    <div class="fp-inline-fields">
                        <div>
                            <label for="buyout_asset_tag">{{ trans('admin/forms/faculty-program.buyout_asset_tag') }}</label>
                            <input type="text" id="buyout_asset_tag" name="buyout_asset_tag" class="form-control input-sm" maxlength="191"
                                   value="{{ old('buyout_asset_tag', $priorAsset?->asset_tag) }}">
                        </div>
                        <div>
                            <label for="buyout_serial">{{ trans('admin/forms/faculty-program.buyout_serial') }}</label>
                            <input type="text" id="buyout_serial" name="buyout_serial" class="form-control input-sm" maxlength="191"
                                   value="{{ old('buyout_serial', $priorAsset?->serial) }}">
                        </div>
                    </div>
                    @if (! $priorAsset)
                        <p class="fp-muted" style="margin-top:6px;">{{ trans('admin/forms/faculty-program.buyout_no_match') }}</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Choosing the new machine: guidance only — the actual choosing
             happens in the store this form opens into. --}}
        <div class="fp-card">
            <div class="fp-kicker">{{ trans('admin/forms/faculty-program.newmachine_kicker') }}</div>
            <h2>{{ trans('admin/forms/faculty-program.section_choose_model') }}</h2>
            <p>{{ trans('admin/forms/faculty-program.choose_model_intro') }}</p>
            <ul style="margin-bottom:10px;">
                <li>{{ trans('admin/forms/faculty-program.choose_model_air_13') }}</li>
                <li>{{ trans('admin/forms/faculty-program.choose_model_air_15') }}</li>
                <li>{{ trans('admin/forms/faculty-program.choose_model_pro_14') }}</li>
                <li>{{ trans('admin/forms/faculty-program.choose_model_pro_max') }}</li>
            </ul>
            <p class="fp-muted" style="margin-bottom:12px;">
                {{ trans('admin/forms/faculty-program.choose_model_compare_intro') }}
                <a href="{{ trans('admin/forms/faculty-program.choose_model_compare_url') }}" target="_blank" rel="noopener">
                    {{ trans('admin/forms/faculty-program.choose_model_compare_label') }}
                    <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                </a>
            </p>

            <p>{!! trans('admin/forms/faculty-program.top_up_help_html') !!}</p>
            <label class="fp-opt {{ old('acknowledge_top_up') ? 'fp-selected' : '' }}">
                <input type="checkbox" name="acknowledge_top_up" value="1" {{ old('acknowledge_top_up') ? 'checked' : '' }} required>
                <span class="fp-ctl"></span>
                <span class="fp-opt-text"><strong>{{ trans('admin/forms/faculty-program.top_up_acknowledge') }}</strong></span>
            </label>
            @if ($errors->has('acknowledge_top_up'))
                <p class="fp-error">{{ $errors->first('acknowledge_top_up') }}</p>
            @endif
        </div>

        {{-- Payment for anything above the base. --}}
        <div class="fp-card">
            <div class="fp-kicker">{{ trans('admin/forms/faculty-program.payment_kicker') }}</div>
            <h2>{{ trans('admin/forms/faculty-program.section_payment') }}</h2>
            <p class="fp-muted">{{ trans('admin/forms/faculty-program.payment_help') }}</p>
            <label class="fp-opt {{ old('payment_method') === 'pay_in_full' ? 'fp-selected' : '' }}">
                <input type="radio" name="payment_method" value="pay_in_full" {{ old('payment_method') === 'pay_in_full' ? 'checked' : '' }} required>
                <span class="fp-ctl"></span>
                <span class="fp-opt-text">{{ trans('admin/forms/faculty-program.payment_pay_in_full') }}</span>
            </label>
            <label class="fp-opt {{ old('payment_method') === 'payroll_deduction' ? 'fp-selected' : '' }}">
                <input type="radio" name="payment_method" value="payroll_deduction" {{ old('payment_method') === 'payroll_deduction' ? 'checked' : '' }}>
                <span class="fp-ctl"></span>
                <span class="fp-opt-text">{{ trans('admin/forms/faculty-program.payment_payroll_deduction') }}</span>
            </label>
            @if ($errors->has('payment_method'))
                <p class="fp-error">{{ $errors->first('payment_method') }}</p>
            @endif
        </div>

        {{-- Anything IT should know. --}}
        <div class="fp-card">
            <div class="fp-kicker">{{ trans('admin/forms/faculty-program.notes_kicker') }}</div>
            <h2>{{ trans('admin/forms/faculty-program.section_notes') }}</h2>
            <p class="fp-muted">{{ trans('admin/forms/faculty-program.notes_help') }}</p>
            <textarea name="notes" class="form-control" rows="3" maxlength="65535">{{ old('notes') }}</textarea>
        </div>

        {{-- Terms, then the one signature-like act on this page. --}}
        <div class="fp-card">
            <div class="fp-kicker">{{ trans('admin/forms/faculty-program.terms_kicker') }}</div>
            <h2>{{ trans('admin/forms/faculty-program.section_terms') }}</h2>
            <p class="fp-muted">{{ trans('admin/forms/faculty-program.terms_intro') }}</p>
            <div class="fp-terms">
                <h3>{{ trans('admin/forms/faculty-program.terms_heading') }}</h3>

                <h4>{{ trans('admin/forms/faculty-program.terms_return_title') }}</h4>
                <p>{{ trans('admin/forms/faculty-program.terms_return_p1') }}</p>
                <p>{{ trans('admin/forms/faculty-program.terms_return_p2') }}</p>

                <h4>{{ trans('admin/forms/faculty-program.terms_care_title') }}</h4>
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

                <h4>{{ trans('admin/forms/faculty-program.terms_physical_security_title') }}</h4>
                <p>{{ trans('admin/forms/faculty-program.terms_physical_security_body') }}</p>

                <h4>{{ trans('admin/forms/faculty-program.terms_data_security_title') }}</h4>
                <p>{{ trans('admin/forms/faculty-program.terms_data_security_body') }}</p>

                <h4>{{ trans('admin/forms/faculty-program.terms_security_threats_title') }}</h4>
                <p>{{ trans('admin/forms/faculty-program.terms_security_threats_body') }}</p>

                <h4>{{ trans('admin/forms/faculty-program.terms_software_title') }}</h4>
                <p>{{ trans('admin/forms/faculty-program.terms_software_body') }}</p>

                <h4>{{ trans('admin/forms/faculty-program.terms_top_up_title') }}</h4>
                <p style="margin-bottom:0;">{{ trans('admin/forms/faculty-program.terms_top_up_body') }}</p>
            </div>
            <label class="fp-opt {{ old('accept_terms') ? 'fp-selected' : '' }}" style="margin-top:12px;">
                <input type="checkbox" name="accept_terms" value="1" {{ old('accept_terms') ? 'checked' : '' }} required>
                <span class="fp-ctl"></span>
                <span class="fp-opt-text"><strong>{{ trans('admin/forms/faculty-program.terms_accept') }}</strong></span>
            </label>
            @if ($errors->has('accept_terms'))
                <p class="fp-error">{{ $errors->first('accept_terms') }}</p>
            @endif
        </div>

        <div class="fp-submit">
            <button type="submit" class="btn btn-primary btn-lg">
                {{ trans('admin/forms/faculty-program.submit') }}
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </button>
        </div>
    </form>

</div>

<script>
// The selected outline follows the control. Pure presentation; the form
// posts identically without it.
document.querySelectorAll('.fp-opt input').forEach(function (input) {
    input.addEventListener('change', function () {
        var group = input.type === 'radio'
            ? document.querySelectorAll('.fp-opt input[name="' + input.name + '"]')
            : [input];
        group.forEach(function (peer) {
            peer.closest('.fp-opt').classList.toggle('fp-selected', peer.checked);
        });
    });
});

// Picking a different machine re-fills the buyout tag/serial fields, so the
// paperwork always names the laptop the card selection points at.
document.querySelectorAll('input[name="returning_asset_id"][type="radio"]').forEach(function (input) {
    input.addEventListener('change', function () {
        if (!input.checked) return;
        var tag = document.getElementById('buyout_asset_tag');
        var serial = document.getElementById('buyout_serial');
        if (tag) tag.value = input.dataset.tag || '';
        if (serial) serial.value = input.dataset.serial || '';
    });
});
</script>

@stop
