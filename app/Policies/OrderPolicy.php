<?php

namespace App\Policies;

class OrderPolicy extends SnipePermissionsPolicy
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
