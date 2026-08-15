<?php

namespace App\Policies;

/**
 * Requisitions are purchase orders before they have a number, so they share
 * the `orders` permission set with Order / PurchaseOrder rather than
 * introducing a permission column of their own.
 */
class RequisitionPolicy extends SnipePermissionsPolicy
{
    /**
     * Procurement readers (the procurement.view gate: explicit grants and
     * department-granted members alike) can READ these records — the whole
     * paper trail is what the procurement pages exist to show. Writing
     * still requires the orders/procurement edit permissions.
     */
    public function index(\App\Models\User $user)
    {
        return parent::index($user) || \Illuminate\Support\Facades\Gate::allows('procurement.view');
    }

    public function view(\App\Models\User $user, $item = null)
    {
        return parent::view($user, $item) || \Illuminate\Support\Facades\Gate::allows('procurement.view');
    }

    protected function columnName()
    {
        return 'orders';
    }
}
