<?php

namespace App\Policies;

use App\Models\User;

/**
 * Purchase orders share the `orders` permission set with the related
 * Order / LeaseSchedule entities — no separate permission column.
 */
class PurchaseOrderPolicy extends SnipePermissionsPolicy
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

    /**
     * Attaching or reading a purchase order's paperwork.
     *
     * The base policy checks `orders.files`, and no such permission exists
     * in config/permissions.php — so the ability was unreachable for anyone
     * without the blanket `admin`, which is why the documents tab worked for
     * superusers and nothing else. A PO's paperwork is part of editing it
     * (promotion already requires the PDF as evidence), so track the edit
     * right, and keep `orders.files` in the check in case the key is ever
     * added.
     */
    public function files(User $user, $item = null)
    {
        return $user->hasAccess('admin')
            || $user->hasAccess('orders.files')
            || $user->hasAccess('orders.edit')
            || $user->hasAccess('procurement.edit');
    }
}
