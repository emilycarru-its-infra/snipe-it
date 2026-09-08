<?php

namespace App\Http\Controllers;

use App\Actions\CheckoutRequests\CancelCheckoutRequestAction;
use App\Actions\CheckoutRequests\CreateCheckoutRequestAction;
use App\Enums\ActionType;
use App\Exceptions\AssetNotRequestable;
use App\Mail\EmailDelivery;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\RequestAssetCancelation;
use App\Notifications\RequestAssetNotification;
use App\Services\Teams\TeamsNotifier;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;

/**
 * This controller handles all actions related to the ability for users
 * to view their own assets in the Snipe-IT Asset Management application.
 *
 * @version    v1.0
 */
class ViewAssetsController extends Controller
{
    /**
     * Show user's assigned assets with optional manager view functionality.
     */
    public function getIndex(Request $request): View|RedirectResponse
    {
        // The tabbed profile page folded into /my — everything it showed
        // (assets, licences, accessories, consumables, EULAs, profile
        // actions) lives there now. The route names stay because sent
        // emails and bookmarks point at them.
        return redirect()->route('my');
    }

    /**
     * Returns view of requestable items for a user.
     */
    public function getRequestableIndex(): View
    {
        $assets = Asset::with('model', 'defaultLoc', 'location', 'assignedTo', 'requests')->Hardware()->RequestableAssets();
        $models = AssetModel::with([
            'category',
            'requests',
            'assets' => function ($q) {
                $q->where('requestable', 1)
                    ->whereHas('status', fn ($s) => $s->where('archived', 0)
                        ->where(fn ($s) => $s->where('deployable', 1)->orWhere('pending', 1)
                        )
                    );
            },
        ])->RequestableModels()->get();

        return view('account/requestable-assets', compact('assets', 'models'));
    }

    public function getRequestItem(Request $request, $itemType, $itemId = null, $cancel_by_admin = false, $requestingUser = null): RedirectResponse
    {
        $item = null;
        $fullItemType = 'App\\Models\\'.studly_case($itemType);

        if ($itemType == 'asset_model') {
            $itemType = 'model';
        }
        $item = call_user_func([$fullItemType, 'find'], $itemId);

        $user = auth()->user();

        $logaction = new Actionlog;
        $logaction->item_id = $data['asset_id'] = $item->id;
        $logaction->item_type = $fullItemType;
        $logaction->created_at = $data['requested_date'] = date('Y-m-d H:i:s');

        if ($user->location_id) {
            $logaction->location_id = $user->location_id;
        }

        $logaction->target_id = $data['user_id'] = auth()->id();
        $logaction->target_type = User::class;

        $data['item_quantity'] = $request->has('request-quantity') ? e($request->input('request-quantity')) : 1;
        $data['requested_by'] = $user->display_name;
        $data['item'] = $item;
        $data['item_type'] = $itemType;
        $data['target'] = auth()->user();

        if ($fullItemType == Asset::class) {
            $data['item_url'] = route('hardware.show', $item->id);
        } else {
            $data['item_url'] = route("view/{$itemType}", $item->id);
        }

        $settings = Setting::getSettings();

        if (($item_request = $item->isRequestedBy($user)) || $cancel_by_admin) {
            $item->cancelRequest($requestingUser);
            $data['item_quantity'] = ($item_request) ? $item_request->qty : 1;
            $logaction->logaction(ActionType::RequestCanceled);

            $this->announceRequest(new RequestAssetCancelation($data), 'request.cancel', $settings);

            return redirect()->back()->with('success')->with('success', trans('admin/hardware/message.requests.canceled'));
        } else {
            $item->request();
            $logaction->logaction('requested');
            $this->announceRequest(new RequestAssetNotification($data), 'request.asset', $settings);

            return redirect()->route('requestable-assets')->with('success')->with('success', trans('admin/hardware/message.requests.success'));
        }
    }

    /**
     * Process a specific requested asset
     *
     * @param  null  $assetId
     */
    public function store(Asset $asset): RedirectResponse
    {
        try {
            CreateCheckoutRequestAction::run($asset, auth()->user());

            return redirect()->route('requestable-assets')->with('success')->with('success', trans('admin/hardware/message.requests.success'));
        } catch (AssetNotRequestable $e) {
            return redirect()->back()->with('error', 'Asset is not requestable');
        } catch (AuthorizationException $e) {
            return redirect()->back()->with('error', trans('admin/hardware/message.requests.error'));
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->with('error', trans('general.something_went_wrong'));
        }
    }

    public function destroy(Asset $asset): RedirectResponse
    {
        try {
            CancelCheckoutRequestAction::run($asset, auth()->user());

            return redirect()->route('requestable-assets')->with('success')->with('success', trans('admin/hardware/message.requests.canceled'));
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->with('error', trans('general.something_went_wrong'));
        }
    }

    public function getRequestedAssets(): View
    {
        return view('account/requested');
    }

    /**
     * Tell whoever fulfils requests that one came in, or was pulled. Email
     * where Settings → Emails still routes it there, a card otherwise.
     *
     * Worth knowing: the email half is gated on alert_email being set, but
     * Setting::routeNotificationForMail() actually delivers to
     * config('mail.reply_to.address') — the gate and the destination have
     * never been the same address. The card path has no such split.
     */
    private function announceRequest(Notification $notification, string $key, Setting $settings): void
    {
        $emailable = $settings->alert_email !== ''
            && $settings->alerts_enabled == '1'
            && ! config('app.lock_passwords');

        if ($emailable && EmailDelivery::shouldEmail($key)) {
            $settings->notify((clone $notification)->locale($settings->locale));
        }

        app(TeamsNotifier::class)->announce($key, $notification);
    }
}
