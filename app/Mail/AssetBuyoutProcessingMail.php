<?php

namespace App\Mail;

use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Starts a priced buyout thread; the buyer still accepts their own agreement. */
class AssetBuyoutProcessingMail extends AssetBuyoutRequestMail
{
    public function __construct(Asset $asset, ?User $requester, public AssetBuyout $buyout)
    {
        parent::__construct($asset, $requester);
        if ($buyout->exists) {
            $buyout->loadMissing('buyer', 'agreement');
        }
    }

    public function envelope(): Envelope
    {
        $envelope = parent::envelope();
        $envelope->subject = $this->overriddenSubject('request.asset_buyout_process', trans('mail.asset_buyout_process_subject', [
            'asset_tag' => $this->asset->asset_tag, 'serial' => $this->asset->serial,
        ]));

        return $envelope;
    }

    public function content(): Content
    {
        return $this->bodyContent('request.asset_buyout_process', 'notifications.markdown.asset-buyout-process', [
            'asset' => $this->asset, 'lease' => $this->lease, 'lessor' => $this->asset->lessor,
            'requester' => $this->requester, 'buyout' => $this->buyout, 'buyer' => $this->buyout->buyer,
            'recorded_cost' => '$'.number_format((float) $this->buyout->quote_total, 2),
            'buyer_cost' => '$'.number_format((float) ($this->buyout->buyer_amount ?? $this->buyout->quote_total), 2),
            'accepted' => (bool) $this->buyout->agreement?->signed_at,
            'acceptance_url' => $this->buyout->agreement?->checkout_acceptance_id
                ? route('account.accept.item', $this->buyout->agreement->checkout_acceptance_id) : null,
        ]);
    }
}
