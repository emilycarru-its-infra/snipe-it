<?php

namespace App\Policies;

use App\Models\OrderInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * An invoice is an order's document, so it is read and written on the order
 * permissions — the same gates as the order pages — and checked against the
 * company of the order it belongs to.
 */
class OrderInvoicePolicy extends OrderPolicy
{
    public function view(User $user, $item = null)
    {
        if ($item instanceof OrderInvoice && $item->order) {
            return Gate::forUser($user)->allows('view', $item->order);
        }

        return parent::view($user, $item);
    }
}
