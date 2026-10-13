<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Requisition;
use App\Models\StoreOrder;
use Illuminate\Support\Collection;

/**
 * The orders page as a board: every order in flight in exactly one stage,
 * from the request waiting on a decision to the hardware waiting to be
 * handed to someone.
 *
 * The procurement pipeline answers "where is the money"; this answers
 * "what is waiting on us, and on the vendor". Same visual language, but
 * every column here is something somebody acts on, so the cards carry
 * models rather than summaries — each card's lightbox holds the actual
 * forms for its stage.
 */
class OrdersBoard
{
    /** How many finished orders the Done column shows; the list holds the rest. */
    public const DONE_RECENT = 12;

    /**
     * Each column's records: approvals, unsent, withVendor and
     * sentRequisitions (store orders and requisitions not yet carried by a
     * vendor Order), ordered, arriving, allocate (arrivals grouped by
     * order, with allocateCount), and done (the latest few, with doneCount).
     *
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $store = ProcurementPipeline::storeOrdersInFlight();

        $orders = Order::actual()
            ->whereIn('status', ['ordered', 'shipped', 'partially_received'])
            ->with(['supplier', 'purchaseOrder', 'items.item' => fn ($q) => $q->withTrashed()])
            ->orderByRaw('COALESCE(vendor_sent_at, order_date, created_at)')
            ->get();

        // An order the vendor has been asked for but nothing has come of
        // yet is "ordered"; anything shipped or partly in is arriving, even
        // when its status has not caught up with its lines.
        [$arriving, $ordered] = $orders->partition(fn (Order $order) => $order->status !== 'ordered'
            || $order->items->contains(fn (OrderItem $line) => $line->isReceived()));

        $arrivals = app(ArrivalAllocator::class)->unallocatedArrivals();
        $allocate = self::groupArrivals($arrivals);
        $holding = $allocate->pluck('order')->filter()->pluck('id')->flip();

        $done = Order::actual()
            ->where('status', 'received')
            ->whereNotIn('id', $holding->keys())
            ->with('supplier')
            ->orderByDesc('updated_at');

        return [
            'approvals' => $store->where('status', 'pending')->values(),
            'unsent' => $store->where('status', 'approved')->values(),
            'withVendor' => $store->where('status', 'ordered')->values(),
            'sentRequisitions' => ProcurementPipeline::sentRequisitions(),
            'ordered' => $ordered->values(),
            'arriving' => $arriving->values(),
            'allocate' => $allocate,
            'allocateCount' => $arrivals->count(),
            'done' => (clone $done)->limit(self::DONE_RECENT)->get(),
            'doneCount' => $done->count(),
        ];
    }

    /**
     * Hardware that arrived without a request, grouped under the order it
     * came on. An arrival no order line points at is grouped on its own,
     * under a null order.
     *
     * @param  Collection<int, Asset>  $arrivals
     * @return Collection<int, array<string, mixed>>
     */
    private static function groupArrivals(Collection $arrivals): Collection
    {
        if ($arrivals->isEmpty()) {
            return collect();
        }

        $lines = OrderItem::where('item_type', Asset::class)
            ->whereIn('item_id', $arrivals->pluck('id'))
            ->with('order.supplier')
            ->get()
            ->keyBy('item_id');

        /** @var Collection<int, array<string, mixed>> $groups */
        $groups = $arrivals->toBase()
            ->groupBy(fn (Asset $asset) => $lines->get($asset->id)->order_id ?? 0)
            ->map(fn (Collection $assets, $orderId) => [
                'order' => $orderId ? $lines->get($assets->first()->id)?->order : null,
                'assets' => $assets->toBase()->values(),
            ])
            ->sortBy(fn (array $group) => $group['order'] === null ? 1 : 0)
            ->values();

        return $groups;
    }

    /** What a store order costs, as the cards and the rail add it up. */
    public static function storeTotal(StoreOrder $order): float
    {
        return (float) $order->total();
    }

    /** What an Order is worth: its recorded cost, or its lines when that is unset. */
    public static function orderTotal(Order $order): float
    {
        return $order->order_cost !== null
            ? (float) $order->order_cost
            : (float) $order->items->sum(fn (OrderItem $line) => $line->lineTotal());
    }
}
