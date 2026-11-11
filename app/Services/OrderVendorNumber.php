<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Order;

/**
 * Records the vendor's number for an order, and makes it the order's own.
 *
 * The shipment webhook and the vendor's invoices arrive under their number and
 * match on `order_number`, so once they issue one the order takes it — and the
 * devices provisioned under our interim number follow. The interim number is
 * kept in `internal_order_number`, so the links and emails already carrying it
 * still open the order.
 *
 * A vendor number another order already goes by is recorded but not taken as
 * the name: two orders with one `order_number` would be a merge nobody asked
 * for. Clearing the vendor number gives the order its interim number back.
 *
 * Changes the model without saving it; the caller saves.
 */
class OrderVendorNumber
{
    /**
     * @return array{old: ?string, new: ?string, order_number_old: ?string, order_number_new: ?string}
     */
    public function assign(Order $order, ?string $vendorNumber): array
    {
        $vendorNumber = filled($vendorNumber) ? trim((string) $vendorNumber) : null;
        $old = $order->vendor_order_number;
        $orderNumberOld = $order->order_number;

        $order->vendor_order_number = $vendorNumber;

        if ($vendorNumber !== null) {
            $taken = Order::withTrashed()
                ->where('order_number', $vendorNumber)
                ->whereKeyNot($order->getKey())
                ->exists();

            if (! $taken && $order->order_number !== $vendorNumber) {
                // The first number the order carried is the one worth keeping:
                // a vendor number corrected later is not an interim number.
                if (blank($order->internal_order_number) && $order->order_number !== $old) {
                    $order->internal_order_number = $order->order_number;
                }
                $this->rename($order, $vendorNumber);
            }
        } elseif (filled($order->internal_order_number) && filled($old) && $order->order_number === $old) {
            $this->rename($order, $order->internal_order_number);
            $order->internal_order_number = null;
        }

        return [
            'old' => $old,
            'new' => $vendorNumber,
            'order_number_old' => $orderNumberOld,
            'order_number_new' => $order->order_number,
        ];
    }

    private function rename(Order $order, string $number): void
    {
        if (filled($order->order_number)) {
            Asset::where('order_number', $order->order_number)->update(['order_number' => $number]);
        }

        $order->order_number = $number;
    }
}
