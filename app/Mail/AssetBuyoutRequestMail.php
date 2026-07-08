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
 * Sent to a leased asset's lessor (the Supplier record in the lessor role)
 * when an admin clicks "Request Buyout" on the asset detail page, asking the
 * lessor's rep for an end-of-lease buyout quote for the specific device.
 *
 * The controller (AssetsController@requestBuyout) addresses it: To the lessor's
 * contact email, Cc the device team + assigned end user + the requesting admin.
 * Reply-To is the device team so the lessor's reply reaches a human inbox.
 */
class AssetBuyoutRequestMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public Asset $asset;
    public ?User $requester;

    /** Lease facts resolved from the asset's custom fields, for the body. */
    public array $lease;

    public function __construct(Asset $asset, ?User $requester = null)
    {
        $this->asset = $asset->loadMissing(['model.fieldset.fields', 'lessor', 'assignedTo']);
        $this->requester = $requester;
        $this->lease = [
            'contract_id'   => $asset->customFieldValueByName('Lease Contract ID'),
            'contract_name' => $asset->customFieldValueByName('Lease Contract Name'),
            'end_date'      => optional($asset->leaseEndDate())->toDateString(),
            'buyout_cost'   => $asset->customFieldValueByName('Buyout Cost'),
            'book_value'    => $asset->customFieldValueByName('Book Value'),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: [new Address(config('leasing.buyout_request_reply_to'))],
            subject: $this->overriddenSubject('request.asset_buyout', trans('mail.asset_buyout_request_subject', [
                'asset_tag' => $this->asset->asset_tag ?? '',
                'serial'    => $this->asset->serial ?? '',
            ])),
        );
    }

    public function content(): Content
    {
        return $this->bodyContent('request.asset_buyout', 'notifications.markdown.asset-buyout-request', [
            'asset'     => $this->asset,
            'lease'     => $this->lease,
            'requester' => $this->requester,
            'lessor'    => $this->asset->lessor,
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
