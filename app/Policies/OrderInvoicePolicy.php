<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * An invoice is an order's document, so it is read and written on the order
 * permissions — the same gates as the order pages — and checked against the
 * company of the order it belongs to, or of its purchase order when the
 * order record is gone.
 */
class OrderInvoicePolicy extends OrderPolicy
{
    public function view(User $user, $item = null)
    {
        if ($item instanceof OrderInvoice) {
            $order = Order::withTrashed()->find($item->order_id);
            if ($order) {
                return Gate::forUser($user)->allows('view', $order);
            }
            if ($item->purchaseOrder) {
                return Gate::forUser($user)->allows('view', $item->purchaseOrder);
            }

            // Neither parent: nobody's company, so only an admin sees it.
            return $user->isSuperUser();
        }

        return parent::view($user, $item);
    }
}
