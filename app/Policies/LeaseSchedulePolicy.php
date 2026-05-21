<?php

namespace App\Policies;

/**
 * Lease schedules share the `orders` permission set with the related
 * Order / PurchaseOrder entities — no separate permission column. This
 * policy exists primarily so `files()` is callable on LeaseSchedule
 * instances (it falls through to the base policy's permission check).
 */
class LeaseSchedulePolicy extends SnipePermissionsPolicy
{
    protected function columnName()
    {
        return 'orders';
    }
}
