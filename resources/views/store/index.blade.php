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

    <div class="row st-layout">
        {{-- Category sidebar, mirroring the CDW eStore's left rail. --}}
        <div class="col-md-2 hidden-sm hidden-xs">
            <div class="box box-default">
                <div class="box-body no-padding">
                    <ul class="nav nav-pills nav-stacked">
                        <li class="{{ $selectedCategory ? '' : 'active' }}">
                            <a href="{{ route('store.index') }}">{{ trans('admin/store/general.all_categories') }}</a>
                        </li>
                        @foreach ($categories as $category)
                            <li class="{{ $selectedCategory === $category ? 'active' : '' }}">
                                <a href="{{ route('store.index', ['category' => $category]) }}">{{ $category }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        {{-- Product grid --}}
        <div class="col-md-7">
            @if ($items->isEmpty())
                <div class="box box-default"><div class="box-body">
                    <p class="text-muted">{{ trans('admin/store/general.store_empty') }}</p>
                </div></div>
            @else
                <div class="st-grid">
                    @foreach ($items as $item)
                        <div class="st-card" data-item-id="{{ $item->id }}">
                            <div class="st-card-img">
                                @if ($item->storeImageUrl())
                                    <img src="{{ $item->storeImageUrl() }}" alt="{{ $item->name }}">
                                @else
                                    <i class="fa-regular fa-image" aria-hidden="true"></i>
                                @endif
                            </div>
                            <div class="st-card-name">{{ $item->name }}</div>
                            <div class="st-card-price">
                                {{ trans('admin/store/general.approx_price') }}
                                {{ \App\Helpers\Helper::formatCurrencyOutput(round($item->effectiveCost())) }}
                            </div>
                            <div class="st-card-actions">
                                <div class="st-qty">
                                    <button type="button" class="btn btn-default st-minus" aria-label="-">&minus;</button>
                                    <input type="number" min="1" max="100" step="1" value="1" class="form-control st-qty-input" aria-label="{{ trans('admin/purchase-orders/general.builder_col_qty') }}">
                                    <button type="button" class="btn btn-default st-plus" aria-label="+">+</button>
                                </div>
                                <button type="button" class="btn btn-primary st-add" data-name="{{ $item->name }}">
                                    {{ trans('admin/store/general.add_to_order') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- The order (cart) --}}
        <div class="col-md-3">
            <div class="box box-primary st-cart-box">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/store/general.your_order') }}</h3>
                </div>
                <div class="box-body">
                    <ul class="st-cart" id="st-cart"></ul>
                    <p class="text-muted" id="st-cart-empty">{{ trans('admin/store/general.cart_empty') }}</p>

                    <div class="form-group" style="margin-top:12px;">
                        <label for="st-notes">{{ trans('admin/store/general.order_note_label') }}</label>
                        <textarea name="notes" id="st-notes" rows="3" class="form-control"
                                  placeholder="{{ trans('admin/store/general.order_note_placeholder') }}"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="st-submit" disabled>
                        {{ trans('admin/store/general.place_order') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="st-line-inputs" hidden></div>
</form>

@include('store._store-js')

@stop
