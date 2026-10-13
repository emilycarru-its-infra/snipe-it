{{-- A store order's facts and lines, as its board lightbox opens on them.
     Needs $order (StoreOrder). --}}
<div class="pp-facts">
    <span>{{ trans('general.total_cost') }}<b class="pp-money">${{ \App\Helpers\Helper::formatCurrencyOutput($order->total()) }}</b></span>
    <span>{{ trans('admin/orders/general.board_lines') }}<b>{{ $order->items->count() }}</b></span>
    <span>{{ trans('admin/orders/general.board_requested_by') }}<b>{{ $order->user?->present()->fullName ?: trans('general.na') }}</b></span>
    @if ($order->vendor_sent_at)
        <span>{{ trans('admin/orders/general.board_sent') }}<b>{{ $order->vendor_sent_at->format('Y-m-d') }}</b></span>
    @endif
</div>
<p style="margin:0 0 8px;">@include('procurement._queue-chips', ['order' => $order])</p>
@if ($order->notes)
    <p class="pq-note">{{ $order->notes }}</p>
@endif
@include('orders._board-lines', ['lines' => $order->items])
