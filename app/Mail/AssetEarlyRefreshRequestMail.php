<?php

namespace App\Mail;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the device team when a staff member clicks "Request early refresh"
 * on their own /my dashboard — something is up with their machine and they
 * want it looked at before its natural refresh. Internal, unlike the buyout
 * request: no lessor involved, the conversation is ours.
 *
 * Addressed by AssetEarlyRefreshRequester: To the device team (config or the
 * Settings → Emails override), Cc the requester so they hold the thread.
 */
class AssetEarlyRefreshRequestMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public Asset $asset;

    public ?User $requester;

    public ?string $note;

    public function __construct(Asset $asset, ?User $requester = null, ?string $note = null)
    {
        $this->asset = $asset->loadMissing(['model.category', 'assignedTo']);
        $this->requester = $requester;
        $this->note = $note;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: [new Address(config('leasing.buyout_request_reply_to'))],
            subject: $this->overriddenSubject('request.early_refresh', trans('mail.early_refresh_request_subject', [
                'name' => $this->requester->display_name ?? '',
                'asset_tag' => $this->asset->asset_tag ?? '',
            ])),
        );
    }

    public function content(): Content
    {
        return $this->bodyContent('request.early_refresh', 'notifications.markdown.asset-early-refresh-request', [
            'asset' => $this->asset,
            'requester' => $this->requester,
            'note' => $this->note,
        ]);
    }
}
