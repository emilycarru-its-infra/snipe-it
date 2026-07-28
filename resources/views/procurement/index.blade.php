@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.procurement') }}
    @parent
@stop

@section('content')

<p class="text-muted">{{ trans('admin/store/general.procurement_intro') }}</p>

@php
    $cards = [
        ['count' => $pendingOrders, 'label' => 'card_pending_orders', 'route' => route('procurement.queue'), 'color' => $pendingOrders ? 'bg-yellow' : 'bg-green', 'icon' => 'fa-solid fa-inbox'],
        ['count' => $approvedUnpulled, 'label' => 'card_approved_unpulled', 'route' => route('procurement.queue', ['status' => 'approved']), 'color' => $approvedUnpulled ? 'bg-aqua' : 'bg-green', 'icon' => 'fa-solid fa-cart-arrow-down'],
        ['count' => $openRequisitions, 'label' => 'card_open_requisitions', 'route' => route('requisitions.index'), 'color' => 'bg-blue', 'icon' => 'fa-solid fa-file-lines'],
        ['count' => $storeItemCount, 'label' => 'card_store_items', 'route' => route('procurement.store-admin'), 'color' => 'bg-purple', 'icon' => 'fa-solid fa-store'],
        ['count' => $catalogCount, 'label' => 'card_catalog', 'route' => route('procurement.store-admin'), 'color' => 'bg-gray', 'icon' => 'fa-solid fa-list'],
        ['count' => $estimateCount, 'label' => 'card_estimates', 'route' => route('procurement.store-admin'), 'color' => $estimateCount ? 'bg-orange' : 'bg-green', 'icon' => 'fa-solid fa-triangle-exclamation'],
    ];
@endphp

<div class="row">
    @foreach ($cards as $card)
        <div class="col-lg-2 col-md-4 col-sm-6">
            <a href="{{ $card['route'] }}">
                <div class="small-box {{ $card['color'] }}">
                    <div class="inner">
                        <h3>{{ $card['count'] }}</h3>
                        <p>{{ trans('admin/store/general.'.$card['label']) }}</p>
                    </div>
                    <div class="icon"><i class="{{ $card['icon'] }}" aria-hidden="true"></i></div>
                </div>
            </a>
        </div>
    @endforeach
</div>

@if (auth()->user()->isSuperUser())
    <div class="row">
        <div class="col-md-6">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/store/general.approvers_title') }}</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/store/general.approvers_intro') }}</p>
                    <form method="POST" action="{{ route('procurement.approvers.save') }}">
                        {{ csrf_field() }}
                        <select class="js-data-ajax" data-endpoint="users" multiple name="approvers[]"
                                data-placeholder="{{ trans('general.select_user') }}" style="width:100%;">
                            @foreach ($approvers as $approver)
                                @if ($approver->user)
                                    <option value="{{ $approver->user_id }}" selected>{{ $approver->user->present()->fullName }}</option>
                                @endif
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-primary" style="margin-top:10px;">{{ trans('general.save') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-body">
                <a href="{{ route('procurement.queue') }}" class="btn btn-primary">{{ trans('admin/store/general.go_queue') }}</a>
                <a href="{{ route('procurement.store-admin') }}" class="btn btn-default">{{ trans('admin/store/general.go_store_admin') }}</a>
                <a href="{{ route('purchase-orders.builder') }}" class="btn btn-default">{{ trans('admin/store/general.go_builder') }}</a>
                <a href="{{ route('requisitions.index') }}" class="btn btn-default">{{ trans('admin/store/general.go_requisitions') }}</a>
                <a href="{{ route('store.index') }}" class="btn btn-default">{{ trans('admin/store/general.go_store') }}</a>
                <a href="{{ route('reports.procurement') }}" class="btn btn-link">{{ trans('admin/store/general.go_reports') }}</a>
            </div>
        </div>
    </div>
</div>
@stop
