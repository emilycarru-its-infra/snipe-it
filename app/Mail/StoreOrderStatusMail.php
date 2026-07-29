<?php

namespace App\Mail;

use App\Models\StoreOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The requester-facing store order lifecycle, one mailable across all
 * six events so every send renders the same order summary. Each event is
 * its own key in the Settings → Emails hub (store.requested,
 * store.approved, …) so subject and body are editable per event.
 */
class StoreOrderStatusMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public const EVENTS = ['requested', 'approved', 'declined', 'ordered', 'shipped', 'arrived'];

    /**
     * @param  array{tracking?: string, serials?: array<int, string>}  $extra
     */
    public function __construct(
        public StoreOrder $order,
        public string $event,
        public array $extra = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->overriddenSubject($this->key(), trans('mail.store_order_'.$this->event.'_subject')),
        );
    }

    public function content(): Content
    {
        // The card for each line reaches through to the catalog row for its
        // specs and photo, so load them once rather than per line.
        $this->order->loadMissing('items.catalogItem.model');

        return $this->bodyContent($this->key(), 'notifications.markdown.store-order-status', [
            'order' => $this->order,
            'event' => $this->event,
            'target' => $this->order->user,
            'tracking' => $this->extra['tracking'] ?? $this->order->tracking_number,
            'serials' => $this->extra['serials'] ?? [],
            'note' => $this->order->decision_notes,
        ]);
    }

    public function key(): string
    {
        return 'store.'.$this->event;
    }

    public function attachments(): array
    {
        return [];
    }
}
