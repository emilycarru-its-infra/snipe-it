@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.procurement') }}
    @parent
@stop

{{-- The doors out of the hub live in the page header rather than in a box at
     the bottom: they are destinations, not content, and putting them above
     the fold keeps the tables below unbroken. --}}
@section('header_right')
    <a href="{{ route('purchase-orders.builder', ['fiscal_year' => \App\Helpers\Helper::currentFiscalYear()]) }}" class="btn btn-sm btn-primary">
        <i class="fas fa-calculator" aria-hidden="true"></i>
        {{ trans('admin/store/general.go_builder') }}
    </a>
    <a href="{{ route('store.index') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.go_store') }}</a>
    <a href="{{ route('procurement.store-admin') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.go_store_admin') }}</a>
    @if (auth()->user()->isSuperUser())
        <button type="button" class="btn btn-sm btn-default" data-toggle="modal" data-target="#approversModal">
            {{ trans('admin/store/general.approvers_title') }}
        </button>
    @endif
    <a href="{{ route('reports.procurement') }}" class="btn btn-sm btn-default">{{ trans('admin/store/general.go_reports') }}</a>
@stop

@section('content')

<p class="text-muted">{{ trans('admin/store/general.procurement_intro') }}</p>

@php
    $cards = [
        ['count' => $pendingOrders, 'label' => 'card_pending_orders', 'anchor' => '#queue', 'color' => $pendingOrders ? 'bg-yellow' : 'bg-green', 'icon' => 'fa-solid fa-inbox'],
        ['count' => $approvedUnpulled, 'label' => 'card_approved_unpulled', 'anchor' => route('procurement.index', ['status' => 'approved']).'#queue', 'color' => $approvedUnpulled ? 'bg-aqua' : 'bg-green', 'icon' => 'fa-solid fa-cart-arrow-down'],
        ['count' => $openRequisitions, 'label' => 'card_open_requisitions', 'anchor' => '#requisitions', 'color' => 'bg-blue', 'icon' => 'fa-solid fa-file-lines'],
        ['count' => $storeItemCount, 'label' => 'card_store_items', 'anchor' => route('procurement.store-admin'), 'color' => 'bg-purple', 'icon' => 'fa-solid fa-store'],
        ['count' => $catalogCount, 'label' => 'card_catalog', 'anchor' => route('procurement.store-admin'), 'color' => 'bg-gray', 'icon' => 'fa-solid fa-list'],
        ['count' => $estimateCount, 'label' => 'card_estimates', 'anchor' => route('procurement.store-admin'), 'color' => $estimateCount ? 'bg-orange' : 'bg-green', 'icon' => 'fa-solid fa-triangle-exclamation'],
    ];
@endphp

<div class="row">
    @foreach ($cards as $card)
        <div class="col-lg-2 col-md-4 col-sm-6">
            <a href="{{ $card['anchor'] }}">
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

{{-- Everything procurement, on one page. Each table keeps its own route in
     the sidebar; these are the same tables, fed by the same APIs. --}}
<div class="row">
    <div class="col-md-12">
        <x-tabs>
            <x-slot:tabnav>
                <x-tabs.nav-item name="queue" label="{{ trans('admin/store/general.queue') }}" count="{{ $pendingOrders }}" />
                <x-tabs.nav-item name="requisitions" label="{{ trans('admin/purchase-orders/general.requisitions') }}" count="{{ $requisitionCount }}" />
                <x-tabs.nav-item name="purchaseorders" label="{{ trans('admin/purchase-orders/general.purchase_orders') }}" count="{{ $purchaseOrderCount }}" />
                <x-tabs.nav-item name="orders" label="{{ trans('admin/orders/general.orders') }}" />
                <x-tabs.nav-item name="suppliers" label="{{ trans('general.suppliers') }}" count="{{ $supplierCount }}" />
                <x-tabs.nav-item name="leases" label="{{ trans('admin/store/general.tab_leases') }}" />
                <x-tabs.nav-item name="agreements" label="{{ trans('admin/store/general.tab_agreements') }}" />
                <x-tabs.nav-item name="depreciation" label="{{ trans('general.depreciation') }}" />
            </x-slot:tabnav>

            <x-slot:tabpanes>
                {{-- Server-rendered: approving and declining are forms, not cells. --}}
                <x-tabs.pane name="queue">
                    <div class="box-body">
                        <div class="pull-right" style="margin-bottom:10px;">
                            <form method="get" action="{{ route('procurement.index') }}">
                                <select name="status" class="form-control input-sm" onchange="this.form.submit()">
                                    @foreach (array_merge($statuses, ['all']) as $status)
                                        <option value="{{ $status }}" {{ $selectedStatus === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </div>
                        <div class="clearfix"></div>
                        @include('procurement._queue-list')
                    </div>
                </x-tabs.pane>

                <x-tabs.pane name="requisitions">
                    <x-table
                        name="requisition"
                        fixed_right_number="1"
                        sort_field="created_at"
                        sort_order="desc"
                        api_url="{{ route('api.requisitions.index') }}"
                        :presenter="\App\Presenters\RequisitionPresenter::dataTableLayout()"
                        export_filename="export-requisitions-{{ date('Y-m-d') }}"
                    />
                </x-tabs.pane>

                <x-tabs.pane name="purchaseorders">
                    <x-table
                        name="purchaseorder"
                        fixed_right_number="1"
                        sort_field="po_number"
                        api_url="{{ route('api.purchase-orders.index') }}"
                        :presenter="\App\Presenters\PurchaseOrderPresenter::dataTableLayout()"
                        export_filename="export-purchase-orders-{{ date('Y-m-d') }}"
                    />
                </x-tabs.pane>

                <x-tabs.pane name="orders">
                    <x-table
                        name="order"
                        fixed_right_number="1"
                        sort_field="order_number"
                        api_url="{{ route('api.orders.index') }}"
                        :presenter="\App\Presenters\OrderPresenter::dataTableLayout()"
                        export_filename="export-orders-{{ date('Y-m-d') }}"
                    />
                </x-tabs.pane>

                <x-tabs.pane name="suppliers">
                    <x-table
                        name="supplier"
                        fixed_right_number="1"
                        sort_field="name"
                        api_url="{{ route('api.suppliers.index') }}"
                        :presenter="\App\Presenters\SupplierPresenter::dataTableLayout()"
                        export_filename="export-suppliers-{{ date('Y-m-d') }}"
                    />
                </x-tabs.pane>

                <x-tabs.pane name="leases">
                    <div class="box-body">
                        @include('partials.lease-document-drop')
                    </div>
                    <x-table
                        name="leasedecision"
                        fixed_right_number="1"
                        sort_field="contract_reference"
                        api_url="{{ route('api.lease-decisions.index') }}"
                        :presenter="\App\Presenters\LeaseDecisionPresenter::dataTableLayout()"
                        export_filename="export-lease-decisions-{{ date('Y-m-d') }}"
                    />
                </x-tabs.pane>

                {{-- Agreements has no table of its own — its list is a
                     procurement report, so the hub pulls in the report
                     fragment the reports page already serves. --}}
                <x-tabs.pane name="agreements">
                    <div class="box-body">
                        <div id="agreements-embed"
                             data-embed-url="{{ route('reports.procurement.user-agreement-ledger', ['embed' => 1]) }}">
                            <p class="text-muted">{{ trans('admin/store/general.tab_loading') }}</p>
                        </div>
                    </div>
                </x-tabs.pane>

                <x-tabs.pane name="depreciation">
                    <x-table
                        name="depreciation"
                        fixed_right_number="1"
                        sort_field="name"
                        api_url="{{ route('api.depreciations.index') }}"
                        :presenter="\App\Presenters\DepreciationPresenter::dataTableLayout()"
                        export_filename="export-depreciations-{{ date('Y-m-d') }}"
                    />
                </x-tabs.pane>
            </x-slot:tabpanes>
        </x-tabs>
    </div>
</div>

@if (auth()->user()->isSuperUser())
    {{-- A setting, not content: who may approve store orders is configured
         once and then forgotten, so it lives behind a button rather than
         taking a box on the page it governs. --}}
    <div class="modal fade" id="approversModal" tabindex="-1" role="dialog" aria-labelledby="approversModalLabel">
        <div class="modal-dialog" role="document">
            <form method="POST" action="{{ route('procurement.approvers.save') }}">
                {{ csrf_field() }}
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ trans('button.cancel') }}">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title" id="approversModalLabel">{{ trans('admin/store/general.approvers_title') }}</h4>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">{{ trans('admin/store/general.approvers_intro') }}</p>
                        <select class="js-data-ajax" data-endpoint="users" multiple name="approvers[]"
                                data-placeholder="{{ trans('general.select_user') }}" style="width:100%;">
                            @foreach ($approvers as $approver)
                                @if ($approver->user)
                                    <option value="{{ $approver->user_id }}" selected>{{ $approver->user->present()->fullName }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('button.cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endif

@stop

@section('moar_scripts')
{{-- Each table's formatters, because the presenters name them by string:
     without these the hub would print "purchaseOrdersLinkFormatter" in every
     cell instead of the row. Suppliers and depreciations need nothing here —
     their formatters are global. --}}
@include('requisitions._table-js')
@include('purchase-orders._table-js')
@include('orders._table-js')
@include('lease-decisions._table-js')
@include('partials.bootstrap-table', ['exportFile' => 'procurement-export', 'search' => true])
<script nonce="{{ csrf_token() }}">
    (function () {
        // The agreements ledger is a report fragment rather than a table, so
        // it is fetched the first time its tab is opened and then left alone.
        var target = document.getElementById('agreements-embed');
        var loaded = false;

        function loadAgreements() {
            if (loaded || ! target) { return; }
            loaded = true;

            fetch(target.dataset.embedUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) { target.innerHTML = html; })
                .catch(function () {
                    loaded = false;
                    target.innerHTML = '<p class="text-danger">{{ trans('general.error') }}</p>';
                });
        }

        $('a[href="#agreements"]').on('shown.bs.tab', loadAgreements);

        // Deep links from the stat tiles open the tab they point at. This
        // has to wait for ready: the layout force-activates the first tab in
        // an inline script that runs after this block, so selecting a tab
        // here directly would be undone a moment later.
        $(function () {
            if (! window.location.hash) { return; }

            var tab = $('a[href="' + window.location.hash + '"][data-toggle="tab"]');
            if (tab.length) { tab.tab('show'); }
            if (window.location.hash === '#agreements') { loadAgreements(); }
        });
    })();
</script>
@stop
