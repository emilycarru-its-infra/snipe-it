@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/orders/general.view') }} - {{ $order->order_number }}
    @parent
@stop

{{-- Page content --}}
@section('content')
@php
    $totalItems = $order->items->count();
    $receivedItems = $order->items->whereNotNull('received_at')->count();
    $equipmentTotal = $order->items->sum(fn ($li) => (float) $li->unit_cost * (int) $li->quantity);
    $warrantyTotal = $order->items->sum(fn ($li) => (float) $li->warranty_cost);
    $showForms = $errors->any();
@endphp
<div class="row">
    <div class="col-lg-8 col-lg-offset-2 col-md-10 col-md-offset-1 col-sm-12 col-sm-offset-0">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title"><x-icon type="order" /> {{ $order->order_number }}</h2>
                <div class="pull-right">
                    @can('update', \App\Models\Order::class)
                        <a href="{{ route('orders.edit', $order->id) }}" class="btn btn-sm btn-primary">
                            <x-icon type="edit" /> {{ trans('general.update') }}
                        </a>
                        @if ($order->status === 'cancelled')
                            <form method="post" action="{{ route('orders.reopen', $order->id) }}" style="display:inline-block">
                                {{ csrf_field() }}
                                <button type="submit" class="btn btn-sm btn-warning">{{ trans('admin/orders/general.reopen_order') }}</button>
                            </form>
                        @else
                            <form method="post" action="{{ route('orders.cancel', $order->id) }}" style="display:inline-block" onsubmit="return confirm('{{ trans('admin/orders/general.cancel_confirm') }}')">
                                {{ csrf_field() }}
                                <button type="submit" class="btn btn-sm btn-danger">{{ trans('admin/orders/general.cancel_order') }}</button>
                            </form>
                        @endif
                    @endcan
                    <a href="{{ route('orders.export', $order->id) }}" class="btn btn-sm btn-default">
                        <x-icon type="download" /> {{ trans('admin/orders/general.export') }}
                    </a>
                    <a href="{{ route('orders.index') }}" class="btn btn-sm btn-default">
                        {{ trans('admin/orders/general.orders') }}
                    </a>
                </div>
            </div>
            <div class="box-body">
                <table class="table table-striped">
                    <tbody>
                        <tr>
                            <td style="width:25%"><strong>{{ trans('general.order_number') }}</strong></td>
                            <td>{{ $order->order_number }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/orders/general.status') }}</strong></td>
                            <td>
                                {{ trans('admin/orders/general.status_'.$order->status) }}
                                @if ($totalItems > 0)
                                    <span class="text-muted">({{ trans('admin/orders/general.received_count', ['received' => $receivedItems, 'total' => $totalItems]) }})</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/purchase-orders/general.purchase_order') }}</strong></td>
                            <td>
                                @if ($order->purchaseOrder)
                                    <a href="{{ route('purchase-orders.show', ['purchase_order' => $order->purchaseOrder->id]) }}">{{ $order->purchaseOrder->po_number }}</a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('general.supplier') }}</strong></td>
                            <td>{{ $order->supplier?->name }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('general.company') }}</strong></td>
                            <td>{{ $order->company?->name }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/orders/general.order_date') }}</strong></td>
                            <td>{{ $order->order_date ? $order->order_date->format('Y-m-d') : '' }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/orders/general.expected_date') }}</strong></td>
                            <td>{{ $order->expected_date ? $order->expected_date->format('Y-m-d') : '' }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/orders/general.received_date') }}</strong></td>
                            <td>{{ $order->received_date ? $order->received_date->format('Y-m-d') : '' }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('admin/orders/general.order_cost') }}</strong></td>
                            <td>{{ $order->order_cost !== null ? Helper::formatCurrencyOutput($order->order_cost) : '' }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('general.notes') }}</strong></td>
                            <td>{!! $order->notes ? nl2br(e($order->notes)) : '' !!}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('general.created_by') }}</strong></td>
                            <td>{{ $order->adminuser?->present()->fullName }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ trans('general.created_at') }}</strong></td>
                            <td>{{ Helper::getFormattedDateObject($order->created_at, 'datetime', false) }}</td>
                        </tr>
                    </tbody>
                </table>

                <h3 style="overflow:hidden">
                    {{ trans('admin/orders/general.line_items') }}
                    @can('update', \App\Models\Order::class)
                        <button type="button" class="btn btn-primary btn-sm pull-right js-order-add-toggle" data-target="order-add-line-item">
                            <x-icon type="create" /> {{ trans('admin/orders/general.add_line_item') }}
                        </button>
                    @endcan
                </h3>
                <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/orders/general.item_type') }}</th>
                            <th>{{ trans('admin/orders/general.item') }}</th>
                            <th>{{ trans('admin/orders/general.description') }}</th>
                            <th>{{ trans('admin/orders/general.quantity') }}</th>
                            <th>{{ trans('admin/orders/general.unit_cost') }}</th>
                            <th>{{ trans('admin/orders/general.warranty_cost') }}</th>
                            <th>{{ trans('admin/orders/general.shipment') }}</th>
                            <th>{{ trans('admin/orders/general.invoice') }}</th>
                            <th>{{ trans('admin/orders/general.received') }}</th>
                            @can('update', \App\Models\Order::class)
                                <th class="text-right">{{ trans('table.actions') }}</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($order->items as $lineItem)
                        <tr>
                            <td>{{ class_basename($lineItem->item_type) }}</td>
                            <td>
                                @php $li = $lineItem->item; @endphp
                                @if ($li && $lineItem->item_type === \App\Models\Asset::class)
                                    <x-icon type="asset" />
                                    <a href="{{ route('hardware.show', $li->id) }}">{{ $li->present()->fullName() }}</a>
                                @elseif ($li)
                                    {!! $li->present()->nameUrl() !!}
                                @else
                                    &mdash;
                                @endif
                            </td>
                            <td>{{ $lineItem->description }}</td>
                            <td>{{ $lineItem->quantity }}</td>
                            <td>{{ $lineItem->unit_cost !== null ? Helper::formatCurrencyOutput($lineItem->unit_cost) : '' }}</td>
                            <td>{{ $lineItem->warranty_cost !== null ? Helper::formatCurrencyOutput($lineItem->warranty_cost) : '' }}</td>
                            <td>
                                @if ($lineItem->shipment)
                                    {{ $lineItem->shipment->tracking_number ?: trans('admin/orders/general.shipment').' #'.$lineItem->shipment->id }}
                                @endif
                            </td>
                            <td>{{ $lineItem->invoice?->invoice_number }}</td>
                            <td>
                                @if ($lineItem->received_at)
                                    <span class="text-success">
                                        <i class="fas fa-check-circle" aria-hidden="true"></i> {{ $lineItem->received_at->format('Y-m-d') }}
                                    </span>
                                @else
                                    <span class="text-muted">{{ trans('admin/orders/general.not_received') }}</span>
                                @endif
                            </td>
                            @can('update', \App\Models\Order::class)
                                <td class="text-right">
                                    @if ($lineItem->received_at)
                                        <form method="post" action="{{ route('orders.items.unreceive', ['order' => $order->id, 'item' => $lineItem->id]) }}" style="display:inline-block">
                                            {{ csrf_field() }}
                                            <button type="submit" class="btn btn-sm btn-default" data-tooltip="true" title="{{ trans('admin/orders/general.unreceive') }}">
                                                <i class="fas fa-undo" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    @else
                                        <form method="post" action="{{ route('orders.items.receive', ['order' => $order->id, 'item' => $lineItem->id]) }}" style="display:inline-block">
                                            {{ csrf_field() }}
                                            <button type="submit" class="btn btn-sm btn-success" data-tooltip="true" title="{{ trans('admin/orders/general.receive') }}">
                                                <i class="fas fa-check" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    @endif
                                    <form method="post" action="{{ route('orders.items.destroy', ['order' => $order->id, 'item' => $lineItem->id]) }}" style="display:inline-block" onsubmit="return confirm('{{ trans('admin/orders/general.remove') }}?')">
                                        {{ csrf_field() }}
                                        {{ method_field('DELETE') }}
                                        <button type="submit" class="btn btn-sm btn-danger" data-tooltip="true" title="{{ trans('admin/orders/general.remove') }}">
                                            <x-icon type="delete" />
                                        </button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10">{{ trans('admin/orders/general.no_line_items') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                    @if (!$order->items->isEmpty())
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-right">{{ trans('admin/orders/general.order_cost') }}</th>
                                <th>{{ Helper::formatCurrencyOutput($equipmentTotal) }}</th>
                                <th>{{ Helper::formatCurrencyOutput($warrantyTotal) }}</th>
                                <th colspan="3" class="text-right">{{ Helper::formatCurrencyOutput($equipmentTotal + $warrantyTotal) }}</th>
                                @can('update', \App\Models\Order::class)
                                    <th></th>
                                @endcan
                            </tr>
                        </tfoot>
                    @endif
                </table>
                </div>

                @can('update', \App\Models\Order::class)
                    <div id="order-add-line-item" class="order-add-form" style="display:{{ $showForms ? 'block' : 'none' }}">
                        <div class="box box-default">
                            <div class="box-body">
                                <form method="post" action="{{ route('orders.items.store', ['order' => $order->id]) }}" class="form-horizontal">
                                    {{ csrf_field() }}

                                    <div class="form-group">
                                        <label for="item_type" class="col-md-3 control-label">{{ trans('admin/orders/general.item_type') }}</label>
                                        <div class="col-md-5">
                                            <select class="form-control" name="item_type" id="item_type" aria-label="item_type">
                                                <option value="asset">{{ trans('admin/orders/general.type_asset') }}</option>
                                                <option value="license">{{ trans('admin/orders/general.type_license') }}</option>
                                                <option value="accessory">{{ trans('admin/orders/general.type_accessory') }}</option>
                                                <option value="consumable">{{ trans('admin/orders/general.type_consumable') }}</option>
                                                <option value="component">{{ trans('admin/orders/general.type_component') }}</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="col-md-3 control-label">{{ trans('admin/orders/general.item') }}</label>
                                        <div class="col-md-7">
                                            @foreach (['asset' => 'hardware', 'license' => 'licenses', 'accessory' => 'accessories', 'consumable' => 'consumables', 'component' => 'components'] as $key => $endpoint)
                                                <div id="li_picker_{{ $key }}" class="li-picker" style="{{ $key === 'asset' ? '' : 'display:none' }}">
                                                    <select class="js-data-ajax" data-endpoint="{{ $endpoint }}" data-placeholder="{{ trans('admin/orders/general.select_item') }}" name="item_id_{{ $key }}" id="li_select_{{ $key }}" style="width:100%" aria-label="item_id_{{ $key }}">
                                                        <option value="">{{ trans('admin/orders/general.select_item') }}</option>
                                                    </select>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="quantity" class="col-md-3 control-label">{{ trans('admin/orders/general.quantity') }}</label>
                                        <div class="col-md-2">
                                            <input type="number" class="form-control" name="quantity" id="quantity" value="1" min="1">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="unit_cost" class="col-md-3 control-label">{{ trans('admin/orders/general.unit_cost') }}</label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" name="unit_cost" id="unit_cost" maxlength="20">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="warranty_cost" class="col-md-3 control-label">{{ trans('admin/orders/general.warranty_cost') }}</label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" name="warranty_cost" id="warranty_cost" maxlength="20">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="shipment_id" class="col-md-3 control-label">{{ trans('admin/orders/general.shipment') }}</label>
                                        <div class="col-md-5">
                                            <select class="form-control" name="shipment_id" id="shipment_id" aria-label="shipment_id">
                                                <option value="">{{ trans('admin/orders/general.unassigned_shipment') }}</option>
                                                @foreach ($order->shipments as $shipment)
                                                    <option value="{{ $shipment->id }}">{{ $shipment->tracking_number ?: trans('admin/orders/general.shipment').' #'.$shipment->id }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="invoice_id" class="col-md-3 control-label">{{ trans('admin/orders/general.invoice') }}</label>
                                        <div class="col-md-5">
                                            <select class="form-control" name="invoice_id" id="invoice_id" aria-label="invoice_id">
                                                <option value="">{{ trans('admin/orders/general.unassigned_invoice') }}</option>
                                                @foreach ($order->invoices as $invoice)
                                                    <option value="{{ $invoice->id }}">{{ $invoice->invoice_number }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="description" class="col-md-3 control-label">{{ trans('admin/orders/general.description') }}</label>
                                        <div class="col-md-7">
                                            <input type="text" class="form-control" name="description" id="description" maxlength="191">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <div class="col-md-offset-3 col-md-7">
                                            <button type="submit" class="btn btn-primary">
                                                <x-icon type="create" /> {{ trans('admin/orders/general.add_line_item') }}
                                            </button>
                                            <button type="button" class="btn btn-default js-order-add-cancel" data-target="order-add-line-item">
                                                {{ trans('general.cancel') }}
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endcan

                <h3 style="overflow:hidden">
                    {{ trans('admin/orders/general.shipments') }}
                    @can('update', \App\Models\Order::class)
                        <button type="button" class="btn btn-primary btn-sm pull-right js-order-add-toggle" data-target="order-add-shipment">
                            <x-icon type="create" /> {{ trans('admin/orders/general.add_shipment') }}
                        </button>
                    @endcan
                </h3>
                <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('general.tracking_number') }}</th>
                            <th>{{ trans('admin/orders/general.tracking_carrier') }}</th>
                            <th>{{ trans('admin/orders/general.shipped_date') }}</th>
                            <th>{{ trans('admin/orders/general.received_date') }}</th>
                            <th>{{ trans('admin/orders/general.line_items') }}</th>
                            @can('update', \App\Models\Order::class)
                                <th class="text-right">{{ trans('table.actions') }}</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($order->shipments as $shipment)
                        @php $tracking_url = Helper::trackingUrl($shipment->tracking_carrier, $shipment->tracking_number); @endphp
                        <tr>
                            <td>
                                @if ($shipment->tracking_number && $tracking_url)
                                    <a href="{{ $tracking_url }}" target="_blank" rel="noopener">{{ $shipment->tracking_number }}</a>
                                @else
                                    {{ $shipment->tracking_number }}
                                @endif
                            </td>
                            <td>{{ $shipment->tracking_carrier }}</td>
                            <td>{{ $shipment->shipped_date ? $shipment->shipped_date->format('Y-m-d') : '' }}</td>
                            <td>{{ $shipment->received_date ? $shipment->received_date->format('Y-m-d') : '' }}</td>
                            <td>{{ $order->items->where('shipment_id', $shipment->id)->count() }}</td>
                            @can('update', \App\Models\Order::class)
                                <td class="text-right">
                                    @unless ($shipment->received_date)
                                        <form method="post" action="{{ route('orders.shipments.receive', ['order' => $order->id, 'shipment' => $shipment->id]) }}" style="display:inline-block">
                                            {{ csrf_field() }}
                                            <button type="submit" class="btn btn-sm btn-success" data-tooltip="true" title="{{ trans('admin/orders/general.mark_received') }}">
                                                <i class="fas fa-check" aria-hidden="true"></i> {{ trans('admin/orders/general.mark_received') }}
                                            </button>
                                        </form>
                                    @endunless
                                    <form method="post" action="{{ route('orders.shipments.destroy', ['order' => $order->id, 'shipment' => $shipment->id]) }}" style="display:inline-block" onsubmit="return confirm('{{ trans('admin/orders/general.remove') }}?')">
                                        {{ csrf_field() }}
                                        {{ method_field('DELETE') }}
                                        <button type="submit" class="btn btn-sm btn-danger" data-tooltip="true" title="{{ trans('admin/orders/general.remove') }}">
                                            <x-icon type="delete" />
                                        </button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">{{ trans('admin/orders/general.no_shipments') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
                </div>

                @can('update', \App\Models\Order::class)
                    <div id="order-add-shipment" class="order-add-form" style="display:{{ $showForms ? 'block' : 'none' }}">
                        <div class="box box-default">
                            <div class="box-body">
                                <form method="post" action="{{ route('orders.shipments.store', ['order' => $order->id]) }}" class="form-horizontal">
                                    {{ csrf_field() }}

                                    <div class="form-group">
                                        <label for="tracking_number" class="col-md-3 control-label">{{ trans('general.tracking_number') }}</label>
                                        <div class="col-md-5">
                                            <input type="text" class="form-control" name="tracking_number" id="tracking_number" maxlength="191">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="tracking_carrier" class="col-md-3 control-label">{{ trans('admin/orders/general.tracking_carrier') }}</label>
                                        <div class="col-md-5">
                                            <input type="text" class="form-control" name="tracking_carrier" id="tracking_carrier" maxlength="191">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="shipped_date" class="col-md-3 control-label">{{ trans('admin/orders/general.shipped_date') }}</label>
                                        <div class="col-md-4">
                                            <x-input.datepicker name="shipped_date" id="shipped_date" />
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="shipment_received_date" class="col-md-3 control-label">{{ trans('admin/orders/general.received_date') }}</label>
                                        <div class="col-md-4">
                                            <x-input.datepicker name="received_date" id="shipment_received_date" />
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <div class="col-md-offset-3 col-md-7">
                                            <button type="submit" class="btn btn-primary">
                                                <x-icon type="create" /> {{ trans('admin/orders/general.add_shipment') }}
                                            </button>
                                            <button type="button" class="btn btn-default js-order-add-cancel" data-target="order-add-shipment">
                                                {{ trans('general.cancel') }}
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endcan

                <h3 style="overflow:hidden">
                    {{ trans('admin/orders/general.invoices') }}
                    @can('update', \App\Models\Order::class)
                        <button type="button" class="btn btn-primary btn-sm pull-right js-order-add-toggle" data-target="order-add-invoice">
                            <x-icon type="create" /> {{ trans('admin/orders/general.add_invoice') }}
                        </button>
                    @endcan
                </h3>
                <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/orders/general.invoice_number') }}</th>
                            <th>{{ trans('admin/orders/general.invoice_date') }}</th>
                            <th>{{ trans('admin/orders/general.subtotal') }}</th>
                            <th>{{ trans('admin/orders/general.tax_gst') }}</th>
                            <th>{{ trans('admin/orders/general.tax_pst') }}</th>
                            <th>{{ trans('admin/orders/general.shipping') }}</th>
                            <th>{{ trans('admin/orders/general.total') }}</th>
                            <th>{{ trans('admin/orders/general.line_items') }}</th>
                            @can('update', \App\Models\Order::class)
                                <th class="text-right">{{ trans('table.actions') }}</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($order->invoices as $invoice)
                        <tr>
                            <td>{{ $invoice->invoice_number }}</td>
                            <td>{{ $invoice->invoice_date ? $invoice->invoice_date->format('Y-m-d') : '' }}</td>
                            <td>{{ $invoice->subtotal !== null ? Helper::formatCurrencyOutput($invoice->subtotal) : '' }}</td>
                            <td>{{ $invoice->tax_gst !== null ? Helper::formatCurrencyOutput($invoice->tax_gst) : '' }}</td>
                            <td>{{ $invoice->tax_pst !== null ? Helper::formatCurrencyOutput($invoice->tax_pst) : '' }}</td>
                            <td>{{ $invoice->shipping !== null ? Helper::formatCurrencyOutput($invoice->shipping) : '' }}</td>
                            <td>{{ $invoice->total !== null ? Helper::formatCurrencyOutput($invoice->total) : '' }}</td>
                            <td>{{ $order->items->where('invoice_id', $invoice->id)->count() }}</td>
                            @can('update', \App\Models\Order::class)
                                <td class="text-right">
                                    <form method="post" action="{{ route('orders.invoices.destroy', ['order' => $order->id, 'invoice' => $invoice->id]) }}" style="display:inline-block" onsubmit="return confirm('{{ trans('admin/orders/general.remove') }}?')">
                                        {{ csrf_field() }}
                                        {{ method_field('DELETE') }}
                                        <button type="submit" class="btn btn-sm btn-danger" data-tooltip="true" title="{{ trans('admin/orders/general.remove') }}">
                                            <x-icon type="delete" />
                                        </button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">{{ trans('admin/orders/general.no_invoices') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
                </div>

                @can('update', \App\Models\Order::class)
                    <div id="order-add-invoice" class="order-add-form" style="display:{{ $showForms ? 'block' : 'none' }}">
                        <div class="box box-default">
                            <div class="box-body">
                                <form method="post" action="{{ route('orders.invoices.store', ['order' => $order->id]) }}" class="form-horizontal">
                                    {{ csrf_field() }}

                                    <div class="form-group">
                                        <label for="invoice_number" class="col-md-3 control-label">{{ trans('admin/orders/general.invoice_number') }}</label>
                                        <div class="col-md-5">
                                            <input type="text" class="form-control" name="invoice_number" id="invoice_number" maxlength="191" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="invoice_date" class="col-md-3 control-label">{{ trans('admin/orders/general.invoice_date') }}</label>
                                        <div class="col-md-4">
                                            <x-input.datepicker name="invoice_date" id="invoice_date" />
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="subtotal" class="col-md-3 control-label">{{ trans('admin/orders/general.subtotal') }}</label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" name="subtotal" id="subtotal" maxlength="20">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="tax_gst" class="col-md-3 control-label">{{ trans('admin/orders/general.tax_gst') }}</label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" name="tax_gst" id="tax_gst" maxlength="20">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="tax_pst" class="col-md-3 control-label">{{ trans('admin/orders/general.tax_pst') }}</label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" name="tax_pst" id="tax_pst" maxlength="20">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="shipping" class="col-md-3 control-label">{{ trans('admin/orders/general.shipping') }}</label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" name="shipping" id="shipping" maxlength="20">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="total" class="col-md-3 control-label">{{ trans('admin/orders/general.total') }}</label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" name="total" id="total" maxlength="20">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <div class="col-md-offset-3 col-md-7">
                                            <button type="submit" class="btn btn-primary">
                                                <x-icon type="create" /> {{ trans('admin/orders/general.add_invoice') }}
                                            </button>
                                            <button type="button" class="btn btn-default js-order-add-cancel" data-target="order-add-invoice">
                                                {{ trans('general.cancel') }}
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endcan
            </div>
        </div>
    </div>
</div>
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
    (function () {
        document.querySelectorAll('.js-order-add-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-target'));
                if (target) {
                    target.style.display = (target.style.display === 'none') ? 'block' : 'none';
                }
            });
        });

        document.querySelectorAll('.js-order-add-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-target'));
                if (target) { target.style.display = 'none'; }
            });
        });

        var typeSelect = document.getElementById('item_type');
        if (!typeSelect) { return; }
        var keys = ['asset', 'license', 'accessory', 'consumable', 'component'];
        function syncPicker() {
            keys.forEach(function (k) {
                var el = document.getElementById('li_picker_' + k);
                if (el) { el.style.display = (k === typeSelect.value) ? '' : 'none'; }
            });
        }
        typeSelect.addEventListener('change', syncPicker);
        syncPicker();
    })();
</script>
@stop
