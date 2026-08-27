<?php

namespace App\Mail;

use App\Models\Order;
use App\Services\RequisitionVendorCsv;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The order itself, sent to the vendor's reps: part numbers, quantities, the
 * agreed unit costs and the purchase order they bill against. One vendor order
 * under that purchase order — the budget sees several of these in a year.
 *
 * Distinct from {@see StoreVendorOrderMail}, which asks the vendor to quote a
 * basket of store requests. This one is the other end of that loop — the
 * quote has come back, finance has issued the purchase order, and this is us
 * telling them to place it. So it states a total rather than an estimate and
 * carries the part list as a CSV — and nothing else. The purchase order's own
 * paperwork stays with us: the issued PO is an internal document, and the
 * vendor already holds their quote. They get the number, not the file.
 *
 * Deliberately plain: it reads like the email a purchaser would have typed,
 * because that is exactly what it replaces.
 */
class RequisitionVendorOrderMail extends BaseMailable
{
    use Queueable, SerializesModels;

    /**
     * @param  bool  $accepted  the same document, sent back as our acceptance of
     *                          their final quote: the lines at the quoted prices,
     *                          the wording changed from "please quote" to "please
     *                          place". Their desk keys the order from this, so it
     *                          carries everything the request did — the table, the
     *                          CSV, the purchase order — and not a summary of it.
     */
    public function __construct(
        public Order $order,
        public bool $test = false,
        public bool $accepted = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->accepted
            ? $this->overriddenSubject(
                'procurement.quote_accepted',
                trans('mail.purchase_order_quote_accepted_subject', [
                    'reference' => $this->order->reference(),
                    'quote' => $this->order->quote_number ?: $this->order->reference(),
                ])
            )
            : $this->overriddenSubject(
                'procurement.vendor_order',
                trans('mail.requisition_vendor_order_subject', [
                    'reference' => $this->order->reference(),
                    'quote' => $this->quoteSuffix(),
                ])
            );

        // Only name an explicit sender when one is configured — with
        // MAIL_FROM_ADDR unset (the test environments), Address(null) throws,
        // and the transport's default from is the right answer anyway.
        $from = config('mail.from.address');

        return new Envelope(
            from: $from ? new Address($from, config('mail.from.name')) : null,
            subject: ($this->test ? trans('mail.store_vendor_order_test_prefix').' ' : '').$subject,
        );
    }

    public function content(): Content
    {
        $this->order->loadMissing('supplier', 'purchaseOrder');

        $lines = $this->order->vendorOrderLines();

        return $this->bodyContent($this->accepted ? 'procurement.quote_accepted' : 'procurement.vendor_order', 'notifications.markdown.requisition-vendor-order', [
            'order' => $this->order,
            'accepted' => $this->accepted,
            'lines' => $lines,
            'reference' => $this->order->reference(),
            'supplier' => $this->order->supplier,
            'lineCount' => $lines->count(),
            // Called out in the body rather than left to be noticed: these are
            // the lines the vendor has to price and issue numbers for.
            'specialLines' => $this->order->specialRequestLines(),
        ]);
    }

    /**
     * The part list as a file their desk can key from. Only that: the
     * purchase order's filed documents are deliberately not attached. The
     * issued PO is our paperwork, the vendor's quote is already theirs, and
     * an order that went out with the PO PDF stapled to it once was the
     * reason this stopped. The numbers in the body are the order.
     */
    public function attachments(): array
    {
        $csv = new RequisitionVendorCsv($this->order);

        return [
            Attachment::fromData(fn () => $csv->contents(), $csv->filename())
                ->withMime('text/csv'),
        ];
    }

    /** " — quote PZFD093" when there is one, nothing when there isn't. */
    private function quoteSuffix(): string
    {
        return filled($this->order->quote_number)
            ? trans('mail.requisition_vendor_order_subject_quote', ['quote' => $this->order->quote_number])
            : '';
    }
}
