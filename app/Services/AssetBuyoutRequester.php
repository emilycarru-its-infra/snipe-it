<?php

namespace App\Services;

use App\Enums\ActionType;
use App\Mail\AssetBuyoutRequestMail;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the lease-buyout quote request for an asset to its lessor — the one
 * email the "Request Buyout" button fires, whether an admin clicks it on the
 * asset detail page or the assigned person clicks it on their own /my page.
 *
 * Addressing: To the lessor's contact email plus any extra recipients from
 * Settings → Emails; Cc the device team list, the assigned end user, and
 * whoever asked. Every send is logged to the asset's activity timeline, and
 * that log is also the throttle — one request per asset per 30 days, so a
 * lessor's rep is never mailed twice about the same machine because someone
 * clicked again while waiting.
 */
class AssetBuyoutRequester
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
            ->where('action_type', ActionType::BuyoutRequested->value)
            ->where('created_at', '>=', now()->subDays(self::REQUEST_COOLDOWN_DAYS))
            ->latest('created_at')
            ->first();
    }

    /**
     * Send the request. Returns a translation key describing why it could
     * not be sent, or null on success.
     */
    public function send(Asset $asset, ?User $requester): ?string
    {
        if (! $asset->isOnActiveLease()) {
            return 'general.request_buyout_not_eligible';
        }

        if (! $asset->lessor || ! filled($asset->lessor->email)) {
            return 'general.request_buyout_missing_email';
        }

        if ($this->pendingRequest($asset)) {
            return 'general.request_buyout_already_requested';
        }

        // To: the lessor's contact email plus any extra recipients configured in
        // Settings → Emails for the buyout email (a Supplier record only holds a
        // single email, but e.g. CCA Financial fields a second rep). The CMS
        // Recipients override wins; the config value is the seeded default.
        $extraRecipients = EmailTemplate::recipientsFor(
            'request.asset_buyout',
            config('leasing.buyout_request_extra_recipients')
        );
        $to = array_values(array_unique(array_filter(array_merge([$asset->lessor->email], $extraRecipients))));

        // Cc: the team list (CMS CC override wins, else the comma-separated
        // config default), the assigned end user (only when the asset is checked
        // out to a real User with an email), and whoever asked. De-dupe and
        // drop any address already in To so it never lands in both.
        $cc = EmailTemplate::ccFor('request.asset_buyout', config('leasing.buyout_request_cc'));
        if (($asset->assignedTo instanceof User) && filled($asset->assignedTo->email)) {
            $cc[] = $asset->assignedTo->email;
        }
        if ($requester && filled($requester->email)) {
            $cc[] = $requester->email;
        }
        $cc = array_values(array_diff(array_unique(array_filter($cc)), $to));

        Mail::to($to)
            ->cc($cc)
            ->send(new AssetBuyoutRequestMail($asset, $requester));

        // Record the request on the asset's activity timeline.
        $log = new Actionlog;
        $log->item_type = Asset::class;
        $log->item_id = $asset->id;
        $log->setAttribute('created_by', $requester?->id);
        $log->target_id = $asset->lessor_id;
        $log->target_type = Supplier::class;
        $log->company_id = $asset->company_id;
        $log->note = trans('mail.asset_buyout_request_subject', [
            'asset_tag' => $asset->asset_tag,
            'serial' => $asset->serial,
        ]);
        $log->logaction(ActionType::BuyoutRequested->value);

        return null;
    }
}
