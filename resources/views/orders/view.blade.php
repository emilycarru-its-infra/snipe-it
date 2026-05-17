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
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ trans('admin/orders/general.item') }}</th>
                            <th>{{ trans('admin/orders/general.description') }}</th>
                            <th>{{ trans('admin/orders/general.quantity') }}</th>
                            <th>{{ trans('admin/orders/general.unit_cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($order->items as $lineItem)
                        <tr>
                            <td>{{ $lineItem->item?->name ?? '—' }}</td>
                            <td>{{ $lineItem->description }}</td>
                            <td>{{ $lineItem->quantity }}</td>
                            <td>{{ $lineItem->unit_cost !== null ? Helper::formatCurrencyOutput($lineItem->unit_cost) : '' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">{{ trans('admin/orders/general.no_line_items') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@stop
