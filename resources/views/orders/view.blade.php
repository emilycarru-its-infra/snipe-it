@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/orders/general.view') }} - {{ $order->order_number }}
    @parent
@stop

{{-- Page content --}}
@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title"><x-icon type="order" /> {{ $order->order_number }}</h2>
                <div class="pull-right">
                    @can('update', \App\Models\Order::class)
                        <a href="{{ route('orders.edit', $order->id) }}" class="btn btn-sm btn-primary">
                            <x-icon type="edit" /> {{ trans('general.update') }}
                        </a>
                    @endcan
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
                            <td>{{ trans('admin/orders/general.status_'.$order->status) }}</td>
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
                            <td><strong>{{ trans('general.tracking_number') }}</strong></td>
                            <td>
                                @php $tracking_url = Helper::trackingUrl($order->tracking_carrier, $order->tracking_number); @endphp
                                @if ($order->tracking_number && $tracking_url)
                                    <a href="{{ $tracking_url }}" target="_blank" rel="noopener">{{ $order->tracking_number }}</a>
                                @else
                                    {{ $order->tracking_number }}
                                @endif
                                @if ($order->tracking_carrier)
                                    <span class="text-muted">({{ $order->tracking_carrier }})</span>
                                @endif
                            </td>
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

                <h3>{{ trans('admin/orders/general.line_items') }}</h3>
                @php
                    $orderTotal = $order->items->sum(fn ($li) => (float) $li->unit_cost * (int) $li->quantity);
                @endphp
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/orders/general.item_type') }}</th>
                            <th>{{ trans('admin/orders/general.item') }}</th>
                            <th>{{ trans('admin/orders/general.description') }}</th>
                            <th>{{ trans('admin/orders/general.quantity') }}</th>
                            <th>{{ trans('admin/orders/general.unit_cost') }}</th>
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
                            @can('update', \App\Models\Order::class)
                                <td class="text-right">
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
                            <td colspan="6">{{ trans('admin/orders/general.no_line_items') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                    @if (!$order->items->isEmpty())
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-right">{{ trans('admin/orders/general.order_cost') }}</th>
                                <th>{{ Helper::formatCurrencyOutput($orderTotal) }}</th>
                                @can('update', \App\Models\Order::class)
                                    <th></th>
                                @endcan
                            </tr>
                        </tfoot>
                    @endif
                </table>

                @can('update', \App\Models\Order::class)
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h3 class="box-title">{{ trans('admin/orders/general.add_line_item') }}</h3>
                        </div>
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
                                    </div>
                                </div>
                            </form>
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
