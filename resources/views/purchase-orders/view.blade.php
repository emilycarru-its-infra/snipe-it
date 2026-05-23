@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/purchase-orders/general.view') }} - {{ $purchaseOrder->po_number }}
    @parent
@stop

{{-- Page content --}}
@section('content')
@php
    $committed = $purchaseOrder->committedTotal();
    $invoiced = $purchaseOrder->invoicedTotal();
    $remaining = $purchaseOrder->remaining();
    $overBudget = $purchaseOrder->isOverBudget();
@endphp
<div class="row">
    <div class="col-lg-8 col-lg-offset-2 col-md-10 col-md-offset-1 col-sm-12 col-sm-offset-0">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title"><x-icon type="order" /> {{ $purchaseOrder->po_number }}</h2>
                <div class="pull-right">
                    @can('update', \App\Models\Order::class)
                        <a href="{{ route('purchase-orders.edit', ['purchase_order' => $purchaseOrder->id]) }}" class="btn btn-sm btn-primary">
                            <x-icon type="edit" /> {{ trans('general.update') }}
                        </a>
                    @endcan
                    <a href="{{ route('purchase-orders.index') }}" class="btn btn-sm btn-default">
                        {{ trans('admin/purchase-orders/general.purchase_orders') }}
                    </a>
                </div>
            </div>
            <div class="box-body">
                <ul class="nav nav-tabs" role="tablist" style="margin-bottom: 15px;">
                    <li role="presentation" class="active">
                        <a href="#po-overview" aria-controls="po-overview" role="tab" data-toggle="tab">
                            {{ trans('general.info') }}
                        </a>
                    </li>
                    <li role="presentation">
                        <a href="#po-documents" aria-controls="po-documents" role="tab" data-toggle="tab">
                            {{ trans('admin/lease-schedules/general.documents') }}
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                <div role="tabpanel" class="tab-pane active" id="po-overview">
                <table class="table table-striped">
                    <tbody>
                        <tr>
                            <td style="width:25%"><strong>{{ trans('admin/purchase-orders/general.po_number') }}</strong></td>
                            <td>{{ $purchaseOrder->po_number }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/purchase-orders/general.title') }}</strong></td>
                            <td>{{ $purchaseOrder->title }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/purchase-orders/general.status') }}</strong></td>
                            <td>{{ trans('admin/purchase-orders/general.status_'.$purchaseOrder->status) }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('general.supplier') }}</strong></td>
                            <td>{{ $purchaseOrder->supplier?->name }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('general.company') }}</strong></td>
                            <td>{{ $purchaseOrder->company?->name }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/purchase-orders/general.fiscal_year') }}</strong></td>
                            <td>{{ $purchaseOrder->fiscal_year }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/purchase-orders/general.cost_center') }}</strong></td>
                            <td>{{ $purchaseOrder->cost_center }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/purchase-orders/general.order_date') }}</strong></td>
                            <td>{{ $purchaseOrder->order_date ? $purchaseOrder->order_date->format('Y-m-d') : '' }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('general.notes') }}</strong></td>
                            <td>{!! $purchaseOrder->notes ? nl2br(e($purchaseOrder->notes)) : '' !!}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('general.created_by') }}</strong></td>
                            <td>{{ $purchaseOrder->adminuser?->present()->fullName }}</td>
                        </tr>
                    </tbody>
                </table>

                <h3>{{ trans('admin/purchase-orders/general.financial_summary') }}</h3>
                <table class="table table-striped">
                    <tbody>
                        <tr>
                            <td style="width:25%"><strong>{{ trans('admin/purchase-orders/general.budget') }}</strong></td>
                            <td>{{ $purchaseOrder->budget !== null ? Helper::formatCurrencyOutput($purchaseOrder->budget) : '' }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/purchase-orders/general.invoiced') }}</strong></td>
                            <td>{{ Helper::formatCurrencyOutput($invoiced) }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/purchase-orders/general.committed') }}</strong></td>
                            <td>{{ Helper::formatCurrencyOutput($committed) }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/purchase-orders/general.remaining') }}</strong></td>
                            <td>
                                @if ($remaining === null)
                                    &mdash;
                                @elseif ($overBudget)
                                    <span class="text-danger"><strong>{{ Helper::formatCurrencyOutput($remaining) }}</strong> &mdash; {{ trans('admin/purchase-orders/general.over_budget') }}</span>
                                @else
                                    {{ Helper::formatCurrencyOutput($remaining) }}
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h3>{{ trans('admin/purchase-orders/general.orders') }}</h3>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('general.order_number') }}</th>
                            <th>{{ trans('admin/orders/general.status') }}</th>
                            <th>{{ trans('general.supplier') }}</th>
                            <th>{{ trans('admin/orders/general.order_date') }}</th>
                            <th>{{ trans('admin/orders/general.line_items') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($purchaseOrder->orders as $childOrder)
                        <tr>
                            <td><a href="{{ route('orders.show', $childOrder->id) }}">{{ $childOrder->order_number }}</a></td>
                            <td>{{ trans('admin/orders/general.status_'.$childOrder->status) }}</td>
                            <td>{{ $childOrder->supplier?->name }}</td>
                            <td>{{ $childOrder->order_date ? $childOrder->order_date->format('Y-m-d') : '' }}</td>
                            <td>{{ $childOrder->items->count() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">{{ trans('admin/purchase-orders/general.no_orders') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
                </div>{{-- /#po-overview --}}

                <div role="tabpanel" class="tab-pane" id="po-documents">
                    @include('partials.object-documents', ['object' => $purchaseOrder, 'object_type' => 'purchase-orders'])
                </div>
                </div>{{-- /.tab-content --}}
            </div>
        </div>
    </div>
</div>
@stop

@section('moar_scripts')
    @can('files', $purchaseOrder)
        @include ('modals.upload-file', ['item_type' => 'purchase-order', 'item_id' => $purchaseOrder->id])
    @endcan
@stop
