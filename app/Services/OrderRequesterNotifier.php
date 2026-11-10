<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreOrder;
use Illuminate\Support\Collection;

class OrderRequesterNotifier
{
    /** A direct PO link is unambiguous only for one vendor order and one request. */
    public function requesters(Order $order): Collection
    {
        if (! $order->purchase_order_id) {
            return collect();
        }

        $legacy = StoreOrder::whereHas('requisition', fn ($q) => $q->where('purchase_order_id', $order->purchase_order_id))->with('user')->get();
        $direct = StoreOrder::where('purchase_order_id', $order->purchase_order_id)->with('user')->get();
        $exact = $direct->filter(fn ($request) => $request->reference() === $order->order_number);
        if ($exact->isNotEmpty()) {
            return $legacy->merge($exact)->unique('id');
        }
        if ($direct->count() === 1 && $order->purchaseOrder->orders()->count() === 1) {
            return $legacy->merge($direct)->unique('id');
        }

        return $legacy;
    }

    public function shipped(Order $order, array $extra = []): void
    {
        foreach ($this->requesters($order) as $request) {
            $tracking = $extra['tracking'] ?? $request->tracking_number;
            if (StoreOrder::whereKey($request->id)->whereNull('shipped_at')->update([
                'shipped_at' => now(), 'tracking_number' => $tracking,
            ]) === 1) {
                StoreOrderNotifier::requester($request->fresh(['items', 'user']), 'shipped', $extra);
            }
        }
    }

    public function arrived(Order $order): void
    {
        $order->refresh();
        if ($order->status !== 'received' || ! $order->purchase_order_id
            || $order->purchaseOrder->orders()->count() !== 1) {
            return;
        }
        foreach ($this->requesters($order) as $request) {
            if (StoreOrder::whereKey($request->id)->whereNull('arrived_at')->update(['arrived_at' => now()]) === 1) {
                StoreOrderNotifier::requester($request->fresh(['items', 'user']), 'arrived');
            }
        }
    }
}
