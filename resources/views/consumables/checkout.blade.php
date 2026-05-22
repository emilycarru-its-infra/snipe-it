@extends('layouts/default')

{{-- Page title --}}
@section('title')
     {{ trans('admin/consumables/general.checkout') }}
@parent
@stop

{{-- Page content --}}
@section('content')

<div class="row">
  <div class="col-md-9">

    <form class="form-horizontal" id="checkout_form" method="post" action="" autocomplete="off">
      <!-- CSRF Token -->
      <input type="hidden" name="_token" value="{{ csrf_token() }}" />

      <div class="box box-default">

        @if ($consumable->id)
          <div class="box-header with-border">
            <div class="box-heading">
              <h2 class="box-title">{{ $consumable->name }} </h2>
            </div>
          </div><!-- /.box-header -->
        @endif

        <div class="box-body">
          @if ($consumable->name)
          <!-- consumable name -->
          <div class="form-group">
            <label class="col-sm-3 control-label">{{ trans('admin/consumables/general.consumable_name') }}</label>
            <div class="col-md-6">
              <p class="form-control-static">{{ $consumable->name }}</p>
            </div>
          </div>
          @endif

          @if ($consumable->company)
              <!-- accessory name -->
              <div class="form-group">
                  <label class="col-sm-3 control-label">{{ trans('general.company') }}</label>
                  <div class="col-md-6">
                      <p class="form-control-static">{!! $consumable->company->present()->formattedNameLink  !!}</p>
                  </div>
              </div>
          @endif


          @if ($consumable->category)
              <!-- category name -->
              <div class="form-group">
                  <label class="col-sm-3 control-label">{{ trans('general.category') }}</label>
                  <div class="col-md-6">
                      <p class="form-control-static">{!! $consumable->category->present()->formattedNameLink  !!}</p>
                  </div>
              </div>
          @endif


          <!-- total -->
          <div class="form-group">
              <label class="col-sm-3 control-label">{{  trans('admin/components/general.total') }}</label>
              <div class="col-md-6">
                  <p class="form-control-static">{{ $consumable->qty }}</p>
              </div>
          </div>

          <!-- remaining -->
          <div class="form-group">
              <label class="col-sm-3 control-label">{{  trans('admin/components/general.remaining') }}</label>
              <div class="col-md-6">
                  <p class="form-control-static">{{ $consumable->numRemaining() }}</p>
              </div>
          </div>




          @php
              // Consumables default to checkout-to-Asset (a consumable like a
              // toner is normally installed in a printer, not handed to a
              // person). The user can still flip to the User tab.
              $consumableCheckoutType = session('checkout_to_type') ?: 'asset';
              $compatibleModelIds = $consumable->compatibleModels->pluck('id')->all();
          @endphp

          <!-- Checkout target -->
          @include ('partials.forms.checkout-selector', ['user_select' => 'true', 'asset_select' => 'true', 'default_type' => 'asset'])
          @include ('partials.forms.edit.user-select', ['translated_name' => trans('general.user'), 'fieldname' => 'assigned_to', 'style' => $consumableCheckoutType == 'user' ? '' : 'display: none;'])
          @include ('partials.forms.edit.asset-select', ['translated_name' => trans('general.select_asset'), 'fieldname' => 'assigned_asset', 'style' => $consumableCheckoutType == 'asset' ? '' : 'display: none;', 'model_ids' => $compatibleModelIds])

          {{-- Surface the model restriction so the user understands why the
               asset list is narrower than usual. --}}
          @if (! empty($compatibleModelIds))
              <div id="compatible-models-filter-note" class="form-group" style="{{ $consumableCheckoutType == 'asset' ? '' : 'display: none;' }}">
                  <div class="col-md-8 col-md-offset-3">
                      <div class="callout callout-info">
                          <i class="fa-solid fa-filter"></i>
                          {{ trans('admin/consumables/general.compatible_models_filter_note') }}
                          <strong>{{ $consumable->compatibleModels->pluck('name')->join(', ') }}</strong>
                      </div>
                  </div>
              </div>
          @endif

          {{-- GL transaction toggle. A checkout to a printer that carries a
               GL code records a journal-transfer line; on by default.
               Uncheck to skip — e.g. a correction, or a printer whose GL
               should not be charged this time. Only meaningful for Asset
               checkouts, so it hides alongside the Asset tab. The hidden
               input guarantees a value is posted even when unchecked. --}}
          <div id="gl-transaction-toggle" class="form-group" style="{{ $consumableCheckoutType == 'asset' ? '' : 'display: none;' }}">
              <div class="col-md-7 col-md-offset-3">
                  <label class="form-control">
                      <input type="hidden" name="create_gl_transaction" value="0">
                      <input type="checkbox" name="create_gl_transaction" value="1"
                             @checked(old('create_gl_transaction', true)) aria-label="create_gl_transaction">
                      {{ trans('admin/consumables/general.create_gl_transaction') }}
                  </label>
                  <p class="help-block">{{ trans('admin/consumables/general.create_gl_transaction_help') }}</p>
              </div>
          </div>


            {{-- Webhook notice: webhook fires whether the consumable is
                 checked out to a User or an Asset, so this notice stays
                 visible regardless of the target. --}}
            @if ($snipeSettings->webhook_endpoint!='')
              <div class="form-group">
                <div class="col-md-8 col-md-offset-3">
                  <div class="callout callout-info">
                    <i class="fab fa-slack"></i>
                    {{ trans('general.webhook_msg_note') }}
                  </div>
                </div>
              </div>
            @endif

            {{-- User-specific notices: acceptance, EULA and checkin email
                 only apply when the consumable is going to a user. The
                 shared notification-callout class lets Snipe's checkout
                 JS hide this block when the target switches to Asset. --}}
            @if ($consumable->requireAcceptance() || (string) $snipeSettings->require_accept_signature === '1' || $consumable->getEula() || (($consumable->category) && ($consumable->category->checkin_email)))
              <div class="form-group notification-callout">
                <div class="col-md-8 col-md-offset-3">
                  <div class="callout callout-info">

                    @if ($consumable->category->require_acceptance=='1')
                      <i class="far fa-envelope"></i>
                      {{ trans('admin/categories/general.required_acceptance') }}
                      <br>
                    @endif

                    @if ($consumable->getEula())
                      <i class="far fa-envelope"></i>
                      {{ trans('admin/categories/general.required_eula') }}
                        <br>
                    @endif

                    @if (($consumable->category) && ($consumable->category->checkin_email))
                      <i class="far fa-envelope"></i>
                      {{ trans('admin/categories/general.checkin_email_notification') }}
                    @endif
                  </div>
                </div>

                <!-- Sign in place checkbox -->
                @if ($consumable->requireAcceptance() || (string) $snipeSettings->require_accept_signature === '1')
                <div id="sign_in_place_div" class="col-md-7 col-md-offset-3">
                  <label class="form-control">
                    <input type="checkbox" value="1" name="sign_in_place" @checked(old('sign_in_place', session('sign_in_place', false))) aria-label="sign_in_place">
                    {{ trans('general.sign_in_place') }}
                  </label>
                  <p class="help-block">
                    {{ trans('general.sign_in_place_help') }}
                  </p>
                </div>
                @endif
              </div>
            @endif

          <!-- Checkout QTY -->
          <div class="form-group {{ $errors->has('qty') ? 'error' : '' }} ">
              <label for="qty" class="col-md-3 control-label">{{ trans('general.qty') }}</label>
              <div class="col-md-7 col-sm-12 required">
                  <div class="col-md-2" style="padding-left:0px">
                    <input class="form-control" type="number" name="checkout_qty" id="checkout_qty" value="1" min="1" max="{{$consumable->numRemaining()}}" maxlength="999999"  />
                  </div>
              </div>
              {!! $errors->first('qty', '<div class="col-md-8 col-md-offset-3"><span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span></div>') !!}
          </div>
          
          <!-- Note -->
          <div class="form-group {{ $errors->has('note') ? 'error' : '' }}">
            <label for="note" class="col-md-3 control-label">{{ trans('admin/hardware/form.notes') }}</label>
            <div class="col-md-7">
              <textarea class="col-md-6 form-control" name="note">{{ old('note') }}</textarea>
              {!! $errors->first('note', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
            </div>
          </div>
        </div> <!-- .box-body -->
            <x-redirect_submit_options
                    index_route="consumables.index"
                    :button_label="trans('general.checkout')"
                    :options="[
                                'index' => trans('admin/hardware/form.redirect_to_all', ['type' => trans('general.consumables')]),
                                'item' => trans('admin/hardware/form.redirect_to_type', ['type' => trans('general.consumable')]),
                                'target' => trans('admin/hardware/form.redirect_to_checked_out_to'),
                                ]"/>
      </div>
    </form>

  </div>
</div>
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
    // Show the asset-only blocks (compatible-models banner, GL transaction
    // toggle) alongside the Asset tab. Snipe's shared checkout JS already
    // shows/hides #assigned_asset on the radio change; we mirror it.
    $(function () {
        var assetOnly = ['compatible-models-filter-note', 'gl-transaction-toggle']
            .map(function (id) { return document.getElementById(id); })
            .filter(Boolean);
        if (!assetOnly.length) { return; }
        $('input[name=checkout_to_type]').on('change', function () {
            var show = this.value === 'asset';
            assetOnly.forEach(function (el) { el.style.display = show ? '' : 'none'; });
        });
    });
</script>
@stop
