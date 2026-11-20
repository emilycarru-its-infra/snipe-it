<?php

namespace App\Mail;

use App\Models\StoreOrder;
use App\Services\StoreOrderAssetProvisioner;
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

    public const EVENTS = ['requested', 'approved', 'declined', 'ordered', 'quoted', 'confirmed', 'shipped', 'arrived', 'inventoried', 'ready'];

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

        $settled = $this->order->confirmed_at !== null
            || in_array($this->event, ['shipped', 'arrived', 'inventoried', 'ready'], true);
        $hasQuote = $this->order->quote_total !== null;

        return $this->bodyContent($this->key(), 'notifications.markdown.store-order-status', [
            'order' => $this->order,
            'event' => $this->event,
            'priceIsEstimate' => ! $settled && ! $hasQuote,
            // Line costs are the catalog snapshot, not allocated vendor prices.
            'showLinePrices' => ! $settled && ! $hasQuote,
            'orderTotal' => $hasQuote ? (float) $this->order->quote_total
                : ($settled ? null : $this->order->total()),
            'totalLabel' => trans($hasQuote
                ? ($settled ? 'mail.store_order_settled_total' : 'mail.store_order_quoted_total')
                : 'mail.store_order_total'),
            // The asset tag exists from the moment the order is sent, long
            // before a serial does, so every email in the journey can name
            // the machine the person and the desk will both look it up by.
            'lineAssets' => $lineAssets = app(StoreOrderAssetProvisioner::class)->assetsByLine($this->order),
            // Flat copy for wording overridden in Settings → Emails.
            'asset_tags' => collect($lineAssets)->flatten()->pluck('asset_tag')->filter()->implode(', '),
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
