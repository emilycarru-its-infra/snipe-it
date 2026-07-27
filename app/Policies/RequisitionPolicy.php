<?php

namespace App\Policies;

/**
 * Requisitions are purchase orders before they have a number, so they share
 * the `orders` permission set with Order / PurchaseOrder rather than
 * introducing a permission column of their own.
 */
class RequisitionPolicy extends SnipePermissionsPolicy
{
    protected function columnName()
    {
        return 'orders';
    }
}
