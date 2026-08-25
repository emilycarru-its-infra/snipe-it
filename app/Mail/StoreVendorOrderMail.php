<?php

namespace App\Mail;

use App\Models\StoreOrder;
use App\Services\VendorOrderCsv;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * The order request that goes to the vendor's reps (CDW): a parts list.
 * Takes one or several store orders — procurement can fire an approved
 * order off on its own or batch a day's worth into a single email.
 *
 * What reaches them is the purchase order their invoice must quote, the
 * account and lease schedule the lines are placed against, and the parts
 * themselves bundled by model. Our store references and the names of the
 * people who asked stay on our side: sixteen laptops is one line saying
 * sixteen, not sixteen blocks naming sixteen faculty.
 *
 * Deliberately plain — it reads like the email a purchaser would have
 * typed, because that is exactly what it replaces.
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
        return $this->orders->map(fn ($order) => $order->reference())->implode(', ');
    }

    public function envelope(): Envelope
    {
        // Our store references mean nothing at the reseller's desk, and
        // sixteen of them made a subject line three lines deep. The purchase
        // order is the one identifier they quote back on an invoice.
        $subject = $this->overriddenSubject(
            'store.vendor_order',
            trans('mail.store_vendor_order_subject', ['reference' => $this->purchaseOrderReference()])
        );

        // Only name an explicit sender when one is configured — with
        // MAIL_FROM_ADDR unset (the test environments), Address(null)
        // throws, and the transport's default from is the right answer
        // anyway.
        $from = config('mail.from.address');

        return new Envelope(
            from: $from ? new Address($from, config('mail.from.name')) : null,
            subject: ($this->test ? trans('mail.store_vendor_order_test_prefix').' ' : '').$subject,
        );
    }

    public function content(): Content
    {
        // Warranty term and the CTO bundle link are per product, so each
        // line reaches through to its catalog row for them.
        $this->orders->each(fn (StoreOrder $order) => $order->loadMissing('items.catalogItem', 'user'));

        $this->orders->each(fn (StoreOrder $order) => $order->loadMissing('purchaseOrder'));

        $groups = \App\Services\VendorOrderLines::grouped($this->orders);

        return $this->bodyContent('store.vendor_order', 'notifications.markdown.store-vendor-order', [
            'orders' => $this->orders,
            'groups' => $groups,
            'supplier' => $this->orders->first()?->supplier(),
            // Bundled lines, not store-order lines: the count has to describe
            // the CSV the desk is about to key.
            'lineCount' => array_sum(array_map(fn ($group) => count($group['lines']), $groups)),
        ]);
    }

    /**
     * The purchase orders this request is placed against, for the subject.
     * Usually exactly one.
     */
    private function purchaseOrderReference(): string
    {
        $this->orders->each(fn (StoreOrder $order) => $order->loadMissing('purchaseOrder'));

        $numbers = $this->orders
            ->map(fn (StoreOrder $order) => $order->purchaseOrder?->po_number)
            ->filter()
            ->unique()
            ->values();

        return $numbers->isEmpty()
            ? trans('mail.store_vendor_order_subject_unreferenced')
            : $numbers->implode(', ');
    }

    /**
     * The part list as a CSV. The email is for a human to read; this is
     * what the reseller's desk keys the order from, so it ships with every
     * send including a test — a test that omitted it would not be testing
     * the thing most likely to be wrong.
     */
    public function attachments(): array
    {
        $csv = new VendorOrderCsv($this->orders);

        return [
            Attachment::fromData(fn () => $csv->contents(), $csv->filename())
                ->withMime('text/csv'),
        ];
    }
}
