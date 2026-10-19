<?php

namespace App\Mail;

use App\Models\LeasePickup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to Receiving once a lessor books a pickup, so the dock knows the day,
 * the window, the load number the driver will quote and what is going. It
 * replaces the forward of the lessor's confirmation that used to be sent by
 * hand, and asks for the signed pickup document back.
 */
class LeasePickupReceivingMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public const KEY = 'request.lease_pickup_receiving';

    public LeasePickup $pickup;

    public bool $updated;

    public function __construct(LeasePickup $pickup, bool $updated = false)
    {
        $this->pickup = $pickup->loadMissing(['assets.model.manufacturer', 'assets.location', 'lessor', 'requester']);
        $this->updated = $updated;
    }

    public function envelope(): Envelope
    {
        $vars = [
            'date' => $this->pickup->scheduled_date?->format('D M j'),
            'lessor' => $this->pickup->lessor->name ?? trans('general.lessor'),
            'load' => $this->pickup->load_number ?: '—',
            'count' => $this->pickup->assets->count(),
        ];

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: filled(config('leasing.pickup_request_reply_to')) ? [new Address(config('leasing.pickup_request_reply_to'))] : [],
            subject: $this->overriddenSubject(self::KEY, trans($this->updated ? 'mail.lease_pickup_receiving_subject_updated' : 'mail.lease_pickup_receiving_subject', $vars)),
        );
    }

    public function content(): Content
    {
        $assets = $this->pickup->assets->sortBy(['lease_contract_id', 'asset_tag'])->values();

        return $this->bodyContent(self::KEY, 'notifications.markdown.lease-pickup-receiving', [
            'pickup' => $this->pickup,
            'assets' => $assets,
            'lessor' => $this->pickup->lessor,
            'requester' => $this->pickup->requester,
            'updated' => $this->updated,
            'when' => trim(($this->pickup->scheduled_date?->format('l, F j, Y') ?? '').', '.($this->pickup->scheduled_window ?: trans('mail.lease_pickup_receiving_tbc')), ', '),
            // Where the pile waits: each holding room once, with its count.
            'locations' => $assets->groupBy(fn ($asset) => $asset->location?->name ?: '—')->map->count()->sortKeys()->all(),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => (new LeasePickupRequestMail($this->pickup))->csv(), 'lease-return-pickup-'.$this->pickup->id.'.csv')
                ->withMime('text/csv'),
        ];
    }
}
