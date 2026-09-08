<?php

namespace App\Actions\CheckoutRequests;

use App\Mail\EmailDelivery;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\RequestAssetCancelation;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Auth\Access\AuthorizationException;

class CancelCheckoutRequestAction
{
    public static function run(Asset $asset, User $user)
    {
        if (! Company::isCurrentUserHasAccess($asset)) {
            throw new AuthorizationException;
        }

        $asset->cancelRequest();

        $asset->decrement('requests_counter', 1);

        $data['item'] = $asset;
        $data['target'] = $user;
        $data['item_quantity'] = 1;
        $settings = Setting::getSettings();

        $logaction = new Actionlog;
        $logaction->item_id = $data['asset_id'] = $asset->id;
        $logaction->item_type = $data['item_type'] = Asset::class;
        $logaction->created_at = $data['requested_date'] = date('Y-m-d H:i:s');
        $logaction->target_id = $data['user_id'] = auth()->id();
        $logaction->target_type = User::class;
        $logaction->location_id = $user->location_id ?? null;
        $logaction->logaction('request canceled');

        $notification = new RequestAssetCancelation($data);

        if (EmailDelivery::shouldEmail('request.cancel')) {
            try {
                $settings->notify(clone $notification);
            } catch (\Exception $e) {
                \Log::warning($e);
            }
        }

        app(TeamsNotifier::class)->announce('request.cancel', $notification);

        return true;
    }
}
