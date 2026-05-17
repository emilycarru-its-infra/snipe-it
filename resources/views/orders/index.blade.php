@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/orders/general.orders') }}
    @parent
@stop

{{-- Page content --}}
@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">{{ trans('admin/orders/general.orders') }}</h2>
                @can('create', \App\Models\Order::class)
                    <div class="pull-right">
                        <a href="{{ route('orders.create') }}" class="btn btn-primary btn-sm">
                            <x-icon type="create" /> {{ trans('admin/orders/general.create') }}
                        </a>
                    </div>
                @endcan
            </div>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-striped snipe-table">
                        <thead>
                            <tr>
                                <th>{{ trans('general.order_number') }}</th>
                                <th>{{ trans('admin/orders/general.status') }}</th>
                                <th>{{ trans('general.supplier') }}</th>
                                <th>{{ trans('general.company') }}</th>
                                <th>{{ trans('admin/orders/general.order_date') }}</th>
                                <th>{{ trans('admin/orders/general.expected_date') }}</th>
                                <th>{{ trans('admin/orders/general.order_cost') }}</th>
                                <th>{{ trans('admin/orders/general.line_items') }}</th>
                                <th class="text-right">{{ trans('table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($orders as $order)
                            <tr>
                                <td><a href="{{ route('orders.show', $order->id) }}">{{ $order->order_number }}</a></td>
                                <td>{{ trans('admin/orders/general.status_'.$order->status) }}</td>
                                <td>{{ $order->supplier?->name }}</td>
                                <td>{{ $order->company?->name }}</td>
                                <td>{{ $order->order_date ? $order->order_date->format('Y-m-d') : '' }}</td>
                                <td>{{ $order->expected_date ? $order->expected_date->format('Y-m-d') : '' }}</td>
                                <td>{{ $order->order_cost }}</td>
                                <td>{{ $order->items_count }}</td>
                                <td class="text-right">
                                    @can('update', \App\Models\Order::class)
                                        <a href="{{ route('orders.edit', $order->id) }}" class="btn btn-sm btn-warning btn-social" data-tooltip="true" title="{{ trans('general.update') }}">
                                            <x-icon type="edit" />
                                        </a>
                                    @endcan
                                    @can('delete', \App\Models\Order::class)
                                        <form method="post" action="{{ route('orders.destroy', $order->id) }}" style="display:inline-block" onsubmit="return confirm('{{ trans('admin/orders/message.delete_confirm') }}')">
                                            {{ csrf_field() }}
                                            {{ method_field('DELETE') }}
                                            <button type="submit" class="btn btn-sm btn-danger btn-social" data-tooltip="true" title="{{ trans('general.delete') }}">
                                                <x-icon type="delete" />
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">{{ trans('admin/orders/message.none') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@stop
