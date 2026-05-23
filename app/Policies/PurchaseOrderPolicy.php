<?php

namespace App\Policies;

/**
 * Purchase orders share the `orders` permission set with the related
 * Order / LeaseSchedule entities — no separate permission column. This
 * policy exists primarily so `files()` is callable on PurchaseOrder
 * instances (it falls through to the base policy's permission check).
 */
class PurchaseOrderPolicy extends SnipePermissionsPolicy
{
    protected function columnName()
    {
        return 'orders';
    }
}
