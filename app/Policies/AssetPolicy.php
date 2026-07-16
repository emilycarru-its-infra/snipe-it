<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy extends CheckoutablePermissionsPolicy
{
    protected function columnName()
    {
        return 'assets';
    }

    public function viewRequestable(User $user, ?Asset $asset = null)
    {
        return $user->hasAccess('assets.view.requestable');
    }

    public function audit(User $user, ?Asset $asset = null)
    {
        return $user->hasAccess('assets.audit');
    }

    /**
     * Send a lease buyout request to the asset's lessor. Its own grant so
     * HR / Finance operations staff can run buyout requests without holding
     * assets.edit; anyone who can edit assets keeps the ability implicitly.
     */
    public function requestBuyout(User $user, ?Asset $asset = null)
    {
        return $user->hasAccess('assets.request_buyout') || $user->hasAccess('assets.edit');
    }

    public function files(User $user, $item = null)
    {
        return $user->hasAccess($this->columnName().'.files');
    }
}
