<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recording the vendor's order number for store orders they have placed.
 *
 * A store order sent for a quote has no vendor order of its own: the
 * request goes out from the store queue, and nothing under the purchase
 * order describes what the vendor placed. When they answer "order #X
 * submitted", this raises that order, under the purchase order the store
 * orders draw on, carrying their lines, the account, the schedule and the
 * quote. The shipment webhook and the invoices then arrive under a number
 * Snipe already knows.
 *
 * It raises no devices. Each store order already has its own placeholders,
 * provisioned when it was approved, and a second set would double the
 * fleet.
 *
 * Safe to repeat: a number already on the purchase order is reused rather
 * than raised twice, which is what lets a mail reader retry.
 */
class StoreOrderVendorOrder
{
    /**
     * @param  Collection<int, StoreOrder>  $orders
     * @return array{ok: bool, error: ?string, order: ?Order, created: bool}
     */
    public function record(Collection $orders, string $vendorOrderNumber, ?PurchaseOrder $purchaseOrder = null): array
    {
        $number = strtoupper(trim($vendorOrderNumber));

        if ($number === '') {
            return $this->failure(trans('admin/store/general.vendor_order_number_required'));
        }

        if ($orders->isEmpty()) {
            return $this->failure(trans('admin/store/general.vendor_order_nothing_open'));
        }

        foreach ($orders as $order) {
            if (! in_array($order->status, ['approved', 'ordered'], true) || $order->vendor_sent_at === null) {
                return $this->failure(trans('admin/store/general.vendor_order_wrong_state', ['reference' => $order->reference()]));
            }
        }

        $purchaseOrder ??= $this->purchaseOrderFor($orders);

        if (! $purchaseOrder) {
            return $this->failure(trans('admin/store/general.vendor_order_no_purchase_order'));
        }

        $elsewhere = $orders->first(fn (StoreOrder $order) => $order->purchase_order_id !== null
            && $order->purchase_order_id !== $purchaseOrder->id);

        if ($elsewhere) {
            return $this->failure(trans('admin/store/general.vendor_order_other_purchase_order', [
                'reference' => $elsewhere->reference(),
                'po' => $elsewhere->purchaseOrder?->po_number,
            ]));
        }

        $existing = Order::where('purchase_order_id', $purchaseOrder->id)
            ->where(fn ($query) => $query->where('order_number', $number)->orWhere('vendor_order_number', $number))
            ->first();

        if ($existing === null && Order::where('order_number', $number)->exists()) {
            return $this->failure(trans('admin/store/general.vendor_order_number_taken', ['number' => $number]));
        }

        return DB::transaction(function () use ($orders, $number, $purchaseOrder, $existing) {
            foreach ($orders as $order) {
                $order->purchase_order_id = $purchaseOrder->id;
                // A number means they placed it, whether or not anyone
                // pressed confirm on the quote first.
                $order->quote_received_at ??= now();
                $order->confirmed_at ??= now();
                $order->status = 'ordered';
                $order->save();
            }

            $vendorOrder = $existing ?? $this->raise($orders, $number, $purchaseOrder);

            $this->followNumber($orders, $purchaseOrder, $number);

            return ['ok' => true, 'error' => null, 'order' => $vendorOrder, 'created' => $existing === null];
        });
    }

    /**
     * The purchase order the store orders draw on — their own, or, for lease
     * orders not yet attached, the one every other order on that schedule
     * already uses. A schedule split across purchase orders answers nothing:
     * which one is a person's call.
     *
     * @param  Collection<int, StoreOrder>  $orders
     */
    private function purchaseOrderFor(Collection $orders): ?PurchaseOrder
    {
        $attached = $orders->pluck('purchase_order_id')->filter()->unique();

        if ($attached->count() === 1) {
            return PurchaseOrder::find($attached->first());
        }

        if ($attached->isNotEmpty()) {
            return null;
        }

        $schedules = $orders->pluck('lease_schedule')->filter()->unique();

        if ($schedules->count() !== 1) {
            return null;
        }

        $schedule = $schedules->first();

        $candidates = Order::where('lease_schedule', $schedule)->whereNotNull('purchase_order_id')
            ->pluck('purchase_order_id')
            ->merge(StoreOrder::where('lease_schedule', $schedule)->whereNotNull('purchase_order_id')->pluck('purchase_order_id'))
            ->unique()
            ->values();

        return $candidates->count() === 1 ? PurchaseOrder::find($candidates->first()) : null;
    }

    /**
     * @param  Collection<int, StoreOrder>  $orders
     */
    private function raise(Collection $orders, string $number, PurchaseOrder $purchaseOrder): Order
    {
        $orders->each(fn (StoreOrder $order) => $order->loadMissing('items.catalogItem'));
        $first = $orders->first();
        $quoteTotals = $orders->pluck('quote_total')->filter(fn ($total) => $total !== null);

        $order = new Order;
        $order->order_number = $number;
        $order->vendor_order_number = $number;
        $order->purchase_order_id = $purchaseOrder->id;
        $order->supplier_id = $first->supplier()?->id;
        $order->company_id = $purchaseOrder->company_id;
        $order->fiscal_year = $purchaseOrder->fiscal_year;
        $order->order_date = now()->startOfDay();
        $order->status = 'ordered';
        $order->is_planned = false;
        $order->funding_account = $first->funding_account;
        $order->lease_schedule = $first->lease_schedule;
        $order->quote_number = $orders->pluck('quote_number')->filter()->unique()->implode(', ') ?: null;
        $order->quote_total = $quoteTotals->isNotEmpty() ? $quoteTotals->sum() : null;
        $order->vendor_sent_at = $orders->pluck('vendor_sent_at')->filter()->min();
        $order->quote_confirmed_at = now();
        $order->notes = trans('admin/store/general.vendor_order_notes', [
            'references' => $orders->map(fn (StoreOrder $o) => $o->reference())->implode(', '),
        ]);
        $order->created_by = auth()->id();

        if (! $order->save()) {
            throw new \RuntimeException(implode(' ', $order->getErrors()->all()));
        }

        foreach ($orders as $storeOrder) {
            foreach ($storeOrder->items as $line) {
                $modelId = $line->catalogItem?->model_id;

                OrderItem::create([
                    'order_id' => $order->id,
                    'purchase_order_id' => $purchaseOrder->id,
                    'item_type' => $modelId ? AssetModel::class : null,
                    'item_id' => $modelId,
                    'catalog_item_id' => $line->catalog_item_id,
                    'description' => $line->description,
                    'vendor_sku' => $line->catalogItem?->vendor_sku,
                    'mfr_part_number' => $line->catalogItem?->mfr_part_number,
                    'quantity' => (int) $line->quantity,
                    'unit_of_measure' => 'EA',
                    'unit_cost' => (float) $line->unit_cost,
                ]);
            }
        }

        return $order;
    }

    /**
     * When these store orders and this vendor order are everything the
     * purchase order holds, their
     * devices take the vendor's number, the same as devices raised under an
     * interim order number do. On a shared purchase order they keep their
     * store reference, which is what ties each device to its requester.
     *
     * @param  Collection<int, StoreOrder>  $orders
     */
    private function followNumber(Collection $orders, PurchaseOrder $purchaseOrder, string $number): void
    {
        $onPurchaseOrder = StoreOrder::where('purchase_order_id', $purchaseOrder->id)->pluck('id');

        if ($onPurchaseOrder->diff($orders->pluck('id'))->isNotEmpty()
            || Order::where('purchase_order_id', $purchaseOrder->id)->count() !== 1) {
            return;
        }

        Asset::whereIn('order_number', $orders->map(fn (StoreOrder $o) => $o->reference())->all())
            ->where(fn ($query) => $query->whereNull('serial')->orWhere('serial', ''))
            ->update(['order_number' => $number]);
    }

    /**
     * @return array{ok: bool, error: ?string, order: ?Order, created: bool}
     */
    private function failure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'order' => null, 'created' => false];
    }
}
