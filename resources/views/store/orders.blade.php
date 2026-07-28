@extends('layouts/default')

@section('title')
    {{ trans('admin/store/general.my_orders') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('store.index') }}" class="btn btn-sm btn-primary">
        {{ trans('admin/store/general.store') }}
    </a>
@stop

@section('content')
<div class="row">
    <div class="col-md-9">
        @if ($orders->isEmpty())
            <div class="box box-default"><div class="box-body">
                <p class="text-muted">{{ trans('admin/store/general.orders_none') }}</p>
            </div></div>
        @endif

        @foreach ($orders as $order)
            @php
                $status = $order->displayStatus();
                $labelClass = ['pending' => 'label-warning', 'approved' => 'label-info', 'processing' => 'label-info',
                               'ordered' => 'label-success', 'shipped' => 'label-success', 'arrived' => 'label-success',
                               'declined' => 'label-danger', 'cancelled' => 'label-default'][$status] ?? 'label-default';
            @endphp
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">
                        {{ $order->created_at->format('M j, Y') }}
                        <span class="label {{ $labelClass }}" style="margin-left:8px;">
                            {{ trans('admin/store/general.order_status_'.$status) }}
                        </span>
                    </h3>
                    @if ($order->status === 'pending')
                        <div class="box-tools pull-right">
                            <form method="POST" action="{{ route('store.orders.cancel', $order->id) }}">
                                {{ csrf_field() }}
                                <button type="submit" class="btn btn-xs btn-default">{{ trans('admin/store/general.cancel_order') }}</button>
                            </form>
                        </div>
                    @endif
                </div>
                <div class="box-body">
                    <table class="table table-condensed" style="margin-bottom:0;">
                        <tbody>
                            @foreach ($order->items as $line)
                                <tr>
                                    <td>{{ $line->description }}</td>
                                    <td class="text-right" style="white-space:nowrap;">&times;{{ $line->quantity }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($order->decision_notes)
                        <p class="text-muted" style="margin:8px 0 0;"><em>{{ $order->decision_notes }}</em></p>
                    @endif
                    @if ($order->tracking_number)
                        <p class="text-muted" style="margin:8px 0 0;">
                            {{ trans('admin/store/general.order_tracking') }} {{ $order->tracking_number }}
                        </p>
                    @endif
                </div>
            </div>
        @endforeach

        {{ $orders->links() }}
    </div>
</div>
@stop
