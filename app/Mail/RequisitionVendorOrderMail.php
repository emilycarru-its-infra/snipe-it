<?php

namespace App\Mail;

use App\Models\Actionlog;
use App\Models\Requisition;
use App\Services\RequisitionVendorCsv;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * The order itself, sent to the vendor's reps: part numbers, quantities, the
 * agreed unit costs and the purchase order they bill against.
 *
 * Distinct from {@see StoreVendorOrderMail}, which asks the vendor to quote a
 * basket of store requests. This one is the other end of that loop — the
 * quote has come back, finance has issued the purchase order, and this is us
 * telling them to place it. So it states a total rather than an estimate,
 * carries the purchase order's own paperwork as attachments, and repeats the
 * printer comments, which are the lines written to be read by the vendor.
 *
 * Deliberately plain: it reads like the email a purchaser would have typed,
 * because that is exactly what it replaces.
 */
class RequisitionVendorOrderMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Requisition $requisition,
        public bool $test = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->overriddenSubject(
            'procurement.vendor_order',
            trans('mail.requisition_vendor_order_subject', [
                'reference' => $this->requisition->display_name,
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
        $this->requisition->loadMissing('items', 'supplier', 'purchaseOrder');

        return $this->bodyContent('procurement.vendor_order', 'notifications.markdown.requisition-vendor-order', [
            'requisition' => $this->requisition,
            'reference' => $this->requisition->display_name,
            'supplier' => $this->requisition->supplier,
            'lineCount' => $this->requisition->items->count(),
            // Called out in the body rather than left to be noticed: these are
            // the lines the vendor has to price and issue numbers for.
            'specialLines' => $this->requisition->specialRequestLines(),
        ]);
    }

    /**
     * The part list as a file their desk can key from, plus whatever the
     * purchase order carries — the issued PO, and the vendor's own quote when
     * it was filed against it. Sending their quote back is not redundant: it
     * is how they match our order to the price they gave, and it is the one
     * document both sides have already agreed to.
     */
    public function attachments(): array
    {
        $csv = new RequisitionVendorCsv($this->requisition);

        $attachments = [
            Attachment::fromData(fn () => $csv->contents(), $csv->filename())
                ->withMime('text/csv'),
        ];

        foreach ($this->purchaseOrderDocuments() as $document) {
            $path = $document->uploads_file_path();

            $attachments[] = Attachment::fromData(fn () => (string) Storage::get($path), $document->filename)
                ->withMime('application/pdf');
        }

        return $attachments;
    }

    /**
     * Documents filed against the purchase order that are actually on disk.
     * A missing file is skipped rather than fatal: the part list and the
     * numbers in the body are the order, and losing an attachment must not
     * be what stops it going out.
     *
     * @return \Illuminate\Support\Collection<int, Actionlog>
     */
    private function purchaseOrderDocuments()
    {
        $purchaseOrder = $this->requisition->purchaseOrder;

        // `exists` and not just null: the email previewer in Settings → Emails
        // renders this mailable against unsaved sample models, and asking an
        // idless purchase order for its uploads is a query for everything.
        if (! $purchaseOrder || ! $purchaseOrder->exists) {
            return collect();
        }

        return $purchaseOrder->uploads()->get()
            ->filter(fn (Actionlog $log) => filled($log->filename) && Storage::exists($log->uploads_file_path()))
            ->values();
    }

    /** " — quote PZFD093" when there is one, nothing when there isn't. */
    private function quoteSuffix(): string
    {
        return filled($this->requisition->quote_number)
            ? trans('mail.requisition_vendor_order_subject_quote', ['quote' => $this->requisition->quote_number])
            : '';
    }
}
