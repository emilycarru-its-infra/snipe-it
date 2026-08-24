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
    <div class="col-md-8">
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
                <div class="row po-summary">
                <div class="col-sm-6">
                <table class="table table-striped">
                    <tbody>
                        <tr>
                            <td style="width:40%"><strong>{{ trans('admin/purchase-orders/general.po_number') }}</strong></td>
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

                </div>

                <div class="col-sm-6">
                <h4 style="margin-top:0;">{{ trans('admin/purchase-orders/general.financial_summary') }}</h4>
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

                </div>
                </div>{{-- /.po-summary --}}

                @php
                    $printerComments = $purchaseOrder->printerComments();
                    $internalComments = $purchaseOrder->internalComments();
                    $orderLines = $purchaseOrder->vendorOrderLines();
                @endphp

                @if ($printerComments)
                    {{-- Typed onto the purchase order the vendor receives. Kept
                         out of the order email itself: it is our keying note. --}}
                    <h3>{{ trans('admin/purchase-orders/general.printer_comments') }}</h3>
                    <div class="well well-sm" style="white-space: pre-wrap;">{{ $printerComments }}</div>
                @endif

                @if ($internalComments)
                    <h3>{{ trans('admin/purchase-orders/general.internal_comments') }}</h3>
                    <div class="well well-sm" style="white-space: pre-wrap; background:#fbfbfb;">{{ $internalComments }}</div>
                @endif

                @if ($orderLines->isNotEmpty())
                    <h3>{{ trans('admin/purchase-orders/general.order_lines') }}</h3>
                    <div class="table-responsive">
                        <table class="table table-striped table-condensed">
                            <thead>
                                <tr>
                                    <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_qty') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.builder_col_description') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.builder_col_mfr') }}</th>
                                    <th>{{ trans('admin/purchase-orders/general.builder_col_sku') }}</th>
                                    <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_unit_cost') }}</th>
                                    <th class="text-right">{{ trans('admin/purchase-orders/general.builder_col_line_total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orderLines as $line)
                                    <tr>
                                        <td class="text-right">{{ $line->quantity }} {{ $line->unit_of_measure ?: 'EA' }}</td>
                                        <td>{{ $line->description }}</td>
                                        <td><x-copy-field :value="$line->mfr_part_number" /></td>
                                        <td><x-copy-field :value="$line->vendor_sku" /></td>
                                        <td class="text-right">{{ Helper::formatCurrencyOutput($line->unit_cost) }}</td>
                                        <td class="text-right">{{ Helper::formatCurrencyOutput($line->lineTotal()) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-right"><strong>{{ trans('admin/purchase-orders/general.order_lines_total') }}</strong></td>
                                    <td class="text-right"><strong>{{ Helper::formatCurrencyOutput($purchaseOrder->vendorTotal()) }}</strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    @foreach ($purchaseOrder->requisitions as $requisition)
                        <p class="text-muted">
                            {{ trans('admin/purchase-orders/general.order_lines_from', ['reqm' => $requisition->requisition_number ?: ('REQ-'.$requisition->id)]) }}
                            <a class="js-lightbox" href="{{ route('requisitions.show', $requisition->id) }}">{{ trans('admin/purchase-orders/general.pipeline_open_requisition') }}</a>
                        </p>
                    @endforeach
                @endif

                @if ($purchaseOrder->storeOrders->whereIn('status', ['approved', 'ordered'])->isNotEmpty())
                    {{-- Requests standing against this budget. Kept apart from
                         committed spend on purpose: a request becomes committed
                         when it reaches a vendor order, and showing it in both
                         places would fund the same device twice. --}}
                    <h3>{{ trans('admin/store/general.po_requested_heading') }}</h3>
                    <p class="text-muted">{{ trans('admin/store/general.po_requested_help') }}</p>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('general.order_number') }}</th>
                                <th>{{ trans('general.user') }}</th>
                                <th>{{ trans('general.item') }}</th>
                                <th class="text-right">{{ trans('general.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($purchaseOrder->storeOrders->whereIn('status', ['approved', 'ordered']) as $storeOrder)
                                <tr>
                                    <td>{{ $storeOrder->reference() }}</td>
                                    <td>{{ $storeOrder->user?->present()->fullName }}</td>
                                    <td>{{ $storeOrder->items->pluck('description')->implode(', ') }}</td>
                                    <td class="text-right">${{ number_format($storeOrder->total(), 2) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td colspan="3"><strong>{{ trans('admin/store/general.po_requested_total') }}</strong></td>
                                <td class="text-right"><strong>${{ number_format($purchaseOrder->requestedTotal(), 2) }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                @endif

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
                            <td><a class="js-lightbox" href="{{ route('orders.show', $childOrder->id) }}">{{ $childOrder->order_number }}</a></td>
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
            </div>
        </div>
        {{-- Documents in the left column with the lines and orders, not at
             the page's foot: the PO PDF and the vendor's quote are what
             somebody opens this page to read, and full-width at the bottom
             they sat below the fold beside an empty gutter. --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fas fa-paperclip"></i> {{ trans('admin/lease-schedules/general.documents') }}</h3>
            </div>
            <div class="box-body">
                @include('partials.object-documents', ['object' => $purchaseOrder, 'object_type' => 'purchase-orders'])
            </div>
        </div>
    </div>

    <div class="col-md-4">
        @include('purchase-orders._vendor-order')
    </div>
</div>
@stop

@section('moar_scripts')
    @can('files', $purchaseOrder)
        @include ('modals.upload-file', ['item_type' => 'purchase-order', 'item_id' => $purchaseOrder->id])
    @endcan
@stop
