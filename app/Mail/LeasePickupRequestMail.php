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
 * Sent to a lessor when a set of devices is bundled for return on the
 * decommissioning lane, asking them to schedule the pickup.
 *
 * It carries everything the lessor and their end-of-lease partner ask for
 * before they can book a truck — the device list, the count per schedule,
 * the preferred dates and the site details — plus the same list as a CSV, so
 * nobody has to ask for "the list of items" afterwards.
 */
class LeasePickupRequestMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public LeasePickup $pickup;

    public function __construct(LeasePickup $pickup)
    {
        $this->pickup = $pickup->loadMissing(['assets.model.manufacturer', 'lessor', 'requester']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: filled(config('leasing.pickup_request_reply_to')) ? [new Address(config('leasing.pickup_request_reply_to'))] : [],
            subject: $this->overriddenSubject('request.lease_pickup', trans('mail.lease_pickup_request_subject', [
                'count' => $this->pickup->assets->count(),
                'schedules' => implode(', ', array_keys($this->pickup->scheduleCounts())),
            ])),
        );
    }

    public function content(): Content
    {
        return $this->bodyContent('request.lease_pickup', 'notifications.markdown.lease-pickup-request', [
            'pickup' => $this->pickup,
            'assets' => $this->pickup->assets->sortBy(['lease_contract_id', 'asset_tag'])->values(),
            'schedules' => $this->pickup->scheduleCounts(),
            'lessor' => $this->pickup->lessor,
            'requester' => $this->pickup->requester,
            // Deployment text: where the equipment waits, receiving hours,
            // site contact, dock access. Empty leaves the section out.
            'siteDetails' => trim(str_replace('\n', "\n", (string) config('leasing.pickup_site_details'))),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->csv(), 'lease-return-pickup-'.$this->pickup->id.'.csv')
                ->withMime('text/csv'),
        ];
    }

    public function csv(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Asset Tag', 'Serial', 'Manufacturer', 'Model', 'Lease Schedule', 'Lease End']);
        foreach ($this->pickup->assets->sortBy(['lease_contract_id', 'asset_tag']) as $asset) {
            fputcsv($out, [
                $asset->asset_tag,
                $asset->serial,
                $asset->model?->manufacturer?->name ?: '',
                $asset->model?->name ?: '',
                $asset->lease_contract_id,
                $asset->lease_end_date,
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
