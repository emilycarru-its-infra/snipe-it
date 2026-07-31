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
                    <div class="form-group" style="margin-top:12px;">
                        <label style="font-weight:600;">{{ trans('admin/store/general.usage_label') }}</label>
                        <label class="radio" style="margin:2px 0;">
                            <input type="radio" name="order_usage" value="assigned" checked>
                            {{ trans('admin/store/general.usage_assigned') }}
                        </label>
                        <label class="radio" style="margin:2px 0;">
                            <input type="radio" name="order_usage" value="shared">
                            {{ trans('admin/store/general.usage_shared') }}
                        </label>
                        <input type="text" name="usage_note" id="st-usage-note" class="form-control input-sm" maxlength="191"
                               placeholder="{{ trans('admin/store/general.usage_note_placeholder') }}" style="display:none; margin-top:4px;">
                    </div>
                    <script>
                    document.querySelectorAll('input[name="order_usage"]').forEach(function (input) {
                        input.addEventListener('change', function () {
                            document.getElementById('st-usage-note').style.display =
                                document.querySelector('input[name="order_usage"]:checked').value === 'shared' ? '' : 'none';
                        });
                    });
                    </script>
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

<script type="application/json" id="st-data">@json($payload)</script>
<script type="application/json" id="st-strings">@json($strings)</script>

@include('store._store-js')

@stop
