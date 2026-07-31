<?php

namespace App\Services;

use App\Enums\ActionType;
use App\Mail\AssetEarlyRefreshRequestMail;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Files a staff member's early-refresh request for their own machine with
 * the device team. The /my counterpart to AssetBuyoutRequester, with the
 * same shape — one email, one activity-log record, one request per asset
 * per 30 days — but pointed inward: To the device team, Cc the requester,
 * no lessor anywhere.
 */
class AssetEarlyRefreshRequester
{
    /** Days before the same asset may be requested again. */
    public const REQUEST_COOLDOWN_DAYS = 30;

    /**
     * The still-cooling-down request for this asset, if one exists.
     */
    public function pendingRequest(Asset $asset): ?Actionlog
    {
        return Actionlog::where('item_type', Asset::class)
            ->where('item_id', $asset->id)
            ->where('action_type', ActionType::EarlyRefreshRequested->value)
            ->where('created_at', '>=', now()->subDays(self::REQUEST_COOLDOWN_DAYS))
            ->latest('created_at')
            ->first();
    }

    /**
     * Send the request. Returns a translation key describing why it could
     * not be sent, or null on success.
     */
    public function send(Asset $asset, ?User $requester, ?string $note = null): ?string
    {
        if (! $asset->isStaffCatalog()) {
            return 'general.request_early_refresh_not_eligible';
        }

        if ($this->pendingRequest($asset)) {
            return 'general.request_early_refresh_already_requested';
        }

        $to = EmailTemplate::recipientsFor(
            'request.early_refresh',
            config('leasing.early_refresh_recipients')
        );

        if (empty($to)) {
            return 'general.request_early_refresh_no_recipients';
        }

        $cc = [];
        if ($requester && filled($requester->email)) {
            $cc[] = $requester->email;
        }
        $cc = array_values(array_diff(array_unique($cc), $to));

        Mail::to($to)
            ->cc($cc)
            ->send(new AssetEarlyRefreshRequestMail($asset, $requester, $note));

        // Record the request on the asset's activity timeline.
        $log = new Actionlog;
        $log->item_type = Asset::class;
        $log->item_id = $asset->id;
        $log->setAttribute('created_by', $requester?->id);
        $log->company_id = $asset->company_id;
        $log->note = trans('mail.early_refresh_request_subject', [
            'name' => $requester->display_name ?? '',
            'asset_tag' => $asset->asset_tag,
        ]);
        $log->logaction(ActionType::EarlyRefreshRequested->value);

        return null;
    }
}
