@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.store') }}
    @parent
@stop

{{-- No header_right link: at this page width it landed against the far
     right edge, a screen away from anything it related to. Someone with
     orders in flight finds them above their order card instead. --}}

@section('content')

<p class="text-muted">{{ trans('admin/store/general.store_intro') }}</p>

<form method="POST" action="{{ route('store.orders.store') }}" id="st-form">
    {{ csrf_field() }}

    {{-- The category filter spans the page above both columns, so the grid
         and the order card start on the same line. Kept out of #st-main:
         with the pills inside it the cart sat a pill-row higher than the
         first product card, and any fixed offset would drift the moment
         the pills wrapped. --}}
    <div class="row">
        <div class="col-md-12" id="st-pills"></div>
    </div>

    <div class="row">
        {{-- Family grid / configurator — all rendered client-side. --}}
        <div class="col-md-8 col-lg-9" id="st-main"></div>

        {{-- The order (cart) --}}
        <div class="col-md-4 col-lg-3">
            {{-- A section heading pushes the product grid down its own
                 height. The same heading box, left blank, keeps the order
                 card's top edge level with the first product card instead
                 of stranding it a heading higher. An empty copy of the real
                 element rather than a measured offset: the two then agree
                 by construction, whatever the heading's font does. --}}
            <div class="st-section-heading st-section-first" id="st-cart-offset" aria-hidden="true" hidden>&nbsp;</div>

            @if ($openOrderCount)
                <a href="{{ route('store.orders') }}" class="btn btn-default btn-block btn-lg st-my-orders">
                    {{ trans('admin/store/general.my_orders') }}
                    <span class="badge">{{ $openOrderCount }}</span>
                </a>
            @endif

            <div class="st-cart-box" id="st-cart-box">
                <h3 class="st-cart-title">{{ trans('admin/store/general.your_order') }}</h3>

                {{-- Arrived via "Request early refresh" on /my: the order is
                     about a specific machine, so the cart says which one and
                     the submission carries it. --}}
                @if ($refreshAsset)
                    <p class="st-refresh-context">
                        {{ trans('admin/store/general.refresh_context', ['model' => $refreshAsset->model?->name ?: trans('general.asset')]) }}
                        <span class="ecu-tag">{{ $refreshAsset->asset_tag }}</span>
                    </p>
                    <input type="hidden" name="refresh_asset_id" value="{{ $refreshAsset->id }}">
                @endif
                <div id="st-cart"></div>
                <p class="text-muted st-cart-empty" id="st-cart-empty">{{ trans('admin/store/general.cart_empty') }}</p>

                <div class="st-cart-summary" id="st-cart-summary" hidden>
                    <div class="st-cart-subtotal">
                        <span>{{ trans('admin/store/general.subtotal') }}</span>
                        <span id="st-cart-total"></span>
                    </div>
                    <p class="st-cart-disclaimer">{{ trans('admin/store/general.approx_disclaimer') }}</p>
                </div>

                {{-- Shared carts: techs and area managers ordering for a
                     lab, classroom or team space rather than themselves.
                     Only rendered for people cleared to place them. --}}
                @if (auth()->user()->canOrderShared())
                    <div class="form-group st-usage" id="st-usage">
                        <label class="st-field-label">{{ trans('admin/store/general.usage_label') }}</label>
                        @foreach (['assigned', 'shared'] as $usage)
                            <label class="ecu-opt ecu-opt-sm {{ old('order_usage', 'assigned') === $usage ? 'ecu-selected' : '' }}">
                                <input type="radio" name="order_usage" value="{{ $usage }}"
                                       {{ old('order_usage', 'assigned') === $usage ? 'checked' : '' }}>
                                <span class="ecu-ctl"></span>
                                <span class="ecu-opt-text">{{ trans('admin/store/general.usage_'.$usage) }}</span>
                            </label>
                        @endforeach

                        {{-- Where the machines land. A shared order has no
                             person to check out to, so the space is the
                             destination — the same Location the checkout
                             screen asks for, picked from the same list. --}}
                        <div id="st-location-wrap" hidden>
                            <label class="st-field-label" for="st-location">{{ trans('admin/store/general.usage_location_label') }}</label>
                            <select name="location_id" id="st-location" class="form-control"
                                    data-placeholder="{{ trans('general.select_location') }}">
                                <option value="">{{ trans('general.select_location') }}</option>
                                @foreach ($locations as $id => $name)
                                    <option value="{{ $id }}" {{ (int) old('location_id') === (int) $id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                            @if ($errors->has('location_id'))
                                <p class="ecu-error">{{ $errors->first('location_id') }}</p>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- A store purchase can be funded by the requester's own
                     department rather than the device budget, and the GL code
                     is how procurement charges it there. Optional — blank
                     means the device budget pays.

                     Never asked of faculty: their laptop is paid for by the
                     program, so the question has no answer they could give.
                     Faculty read an optional field as a required one and stop
                     to ask, which is exactly what happened on wave 2. --}}
                @if ($showGlCode)
                    <div class="form-group" style="margin-top:12px;">
                        <label for="st-gl-code">{{ trans('admin/store/general.gl_code_label') }}</label>
                        <input type="text" name="gl_code" id="st-gl-code" class="form-control" maxlength="64"
                               placeholder="{{ trans('admin/store/general.gl_code_placeholder') }}">
                    </div>
                @endif

                <div class="form-group" style="margin-top:12px;">
                    <label for="st-notes">{{ trans('admin/store/general.order_note_label') }}</label>
                    <textarea name="notes" id="st-notes" rows="3" class="form-control"
                              placeholder="{{ trans('admin/store/general.order_note_placeholder') }}"></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg" id="st-submit" disabled>
                    {{ trans('admin/store/general.place_order') }}
                </button>
            </div>
        </div>
    </div>

    <div id="st-line-inputs" hidden></div>
</form>

{{-- Adding to the catalog from a vendor link.

     Outside the order form, not nested in it: this is its own POST, and a
     form inside a form is not a thing the browser will honour. Placed under
     the grid rather than beside it because it is the answer to having looked
     through the grid and not found the thing. --}}
<div class="st-add-link">
    <h4>{{ trans('admin/store/general.catalog_link_heading') }}</h4>
    <p class="text-muted">{{ trans('admin/store/general.catalog_link_help') }}</p>

    <form method="POST" action="{{ route('store.catalog-items.store') }}" class="st-add-link-form">
        {{ csrf_field() }}
        <label class="sr-only" for="st-catalog-url">{{ trans('admin/store/general.catalog_link_label') }}</label>
        <input type="url" name="url" id="st-catalog-url" class="form-control"
               value="{{ old('url') }}" required
               placeholder="https://www.cdw.ca/product/&hellip;">
        <button type="submit" class="btn btn-default">
            {{ trans('admin/store/general.catalog_link_button') }}
        </button>
    </form>
</div>

{{-- No nonce: the CSP this app sends allows 'unsafe-inline' and defines no
     nonce source, so the attribute does nothing but publish the CSRF token
     into the DOM for any injected script to read. --}}
<style>
.st-add-link { margin: 32px 0 8px; padding-top: 20px; border-top: 1px solid #e5e5e5; }
.st-add-link h4 { margin: 0 0 4px; font-weight: 600; }
.st-add-link p { margin: 0 0 12px; max-width: 62ch; }
.st-add-link-form { display: flex; gap: 8px; align-items: center; max-width: 640px; }
.st-add-link-form .form-control { flex: 1 1 auto; }
.st-add-link-form .btn { flex: 0 0 auto; }
@media (max-width: 600px) {
    .st-add-link-form { flex-direction: column; align-items: stretch; }
}
</style>

<script type="application/json" id="st-data">@json($payload)</script>
<script type="application/json" id="st-strings">@json($strings)</script>

@include('store._store-js')

<script nonce="{{ csrf_token() }}">
(function () {
    var usage = document.getElementById('st-usage');
    if (! usage) {
        return;
    }

    var wrap = document.getElementById('st-location-wrap');
    var select = document.getElementById('st-location');
    var radios = usage.querySelectorAll('input[name="order_usage"]');

    function isShared() {
        return usage.querySelector('input[name="order_usage"]:checked').value === 'shared';
    }

    function sync() {
        wrap.hidden = ! isShared();
        radios.forEach(function (radio) {
            radio.closest('.ecu-opt').classList.toggle('ecu-selected', radio.checked);
        });
    }

    radios.forEach(function (radio) { radio.addEventListener('change', sync); });
    sync();

    // Guarded here rather than with the `required` attribute: select2 hides
    // the real select, and the browser refuses to submit — silently, with
    // no bubble it can place — when a hidden control is required. The cart
    // is client-side state, so bouncing off the server would lose it.
    document.getElementById('st-form').addEventListener('submit', function (e) {
        if (! isShared() || select.value) {
            return;
        }
        e.preventDefault();
        var warning = document.getElementById('st-location-error');
        if (! warning) {
            warning = document.createElement('p');
            warning.id = 'st-location-error';
            warning.className = 'ecu-error';
            warning.textContent = @json(trans('admin/store/general.usage_location_required'));
            wrap.appendChild(warning);
        }
        // select2 hides the real control, so focus its stand-in when one exists.
        (document.querySelector('#st-location-wrap .select2-selection') || select).focus();
    });

    // Several hundred rooms is past the point a native select is usable.
    // select2 is already on the page for the admin forms; if it ever is
    // not, the plain select still works.
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('#st-location').select2({ width: '100%' });
    }
})();
</script>

@stop
