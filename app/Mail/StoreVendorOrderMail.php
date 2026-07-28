<?php

namespace App\Mail;

use App\Models\StoreOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The order request that goes to the vendor's reps (CDW): part numbers,
 * quantities and our internal order reference. Deliberately plain — it
 * reads like the email a purchaser would have typed, because that is
 * exactly what it replaces.
 */
class StoreVendorOrderMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public StoreOrder $order,
        public bool $test = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->overriddenSubject(
            'store.vendor_order',
            trans('mail.store_vendor_order_subject', ['id' => $this->order->id])
        );

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: ($this->test ? trans('mail.store_vendor_order_test_prefix').' ' : '').$subject,
        );
    }

    public function content(): Content
    {
        return $this->bodyContent('store.vendor_order', 'notifications.markdown.store-vendor-order', [
            'order' => $this->order,
            'supplier' => $this->order->supplier(),
        ]);
    }

    public function attachments(): array
    {
        return [];
    }
}
