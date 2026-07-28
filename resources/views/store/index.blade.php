@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.store') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('store.orders') }}" class="btn btn-sm btn-default">
        {{ trans('admin/store/general.my_orders') }}
        @if ($openOrderCount)
            <span class="badge">{{ $openOrderCount }}</span>
        @endif
    </a>
@stop

@section('content')

<p class="text-muted">{{ trans('admin/store/general.store_intro') }}</p>

<form method="POST" action="{{ route('store.orders.store') }}" id="st-form">
    {{ csrf_field() }}

    <div class="row">
        {{-- Family grid / configurator — all rendered client-side. --}}
        <div class="col-md-8 col-lg-9" id="st-main"></div>

        {{-- The order (cart) --}}
        <div class="col-md-4 col-lg-3">
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
