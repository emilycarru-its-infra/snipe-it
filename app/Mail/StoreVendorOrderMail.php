<?php

namespace App\Mail;

use App\Models\StoreOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * The order request that goes to the vendor's reps (CDW): part numbers,
 * quantities and our internal order references. Takes one or several
 * store orders — procurement can fire an approved order off on its own
 * or batch a day's worth into a single email. Deliberately plain — it
 * reads like the email a purchaser would have typed, because that is
 * exactly what it replaces.
 */
class StoreVendorOrderMail extends BaseMailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, StoreOrder>  $orders
     */
    public function __construct(
        public Collection $orders,
        public bool $test = false,
    ) {}

    public function references(): string
    {
        return $this->orders->map(fn ($order) => 'ECU-STORE-'.$order->id)->implode(', ');
    }

    public function envelope(): Envelope
    {
        $subject = $this->overriddenSubject(
            'store.vendor_order',
            trans('mail.store_vendor_order_subject', ['references' => $this->references()])
        );

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: ($this->test ? trans('mail.store_vendor_order_test_prefix').' ' : '').$subject,
        );
    }

    public function content(): Content
    {
        return $this->bodyContent('store.vendor_order', 'notifications.markdown.store-vendor-order', [
            'orders' => $this->orders,
            'references' => $this->references(),
            'supplier' => $this->orders->first()?->supplier(),
        ]);
    }

    public function attachments(): array
    {
        return [];
    }
}
