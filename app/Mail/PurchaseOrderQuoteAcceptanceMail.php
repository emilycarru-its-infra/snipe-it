<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Our acceptance of the vendor's final quote: place it.
 *
 * The other end of {@see RequisitionVendorOrderMail}. That one asks the reps
 * to price and place an order; they answer with a quote; this is the reply
 * that says yes to it. CDW's desk does nothing on a quote until the customer
 * accepts, so without this mail the loop stalls at "quoted" however many
 * facts the purchase order holds.
 *
 * Deliberately short. Their quote number leads because it is what their
 * desk searches on, the purchase order follows because it is what they
 * bill against, and the total is repeated so a mismatch surfaces now rather
 * than on the invoice. The lines are not restated: the quote already has
 * them, and restating them invites a second source of truth.
 */
class PurchaseOrderQuoteAcceptanceMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseOrder $purchaseOrder,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->overriddenSubject(
            'procurement.quote_accepted',
            trans('mail.purchase_order_quote_accepted_subject', [
                'reference' => $this->purchaseOrder->po_number,
                'quote' => $this->purchaseOrder->quote_number ?: $this->purchaseOrder->po_number,
            ])
        );

        // Only name an explicit sender when one is configured — with
        // MAIL_FROM_ADDR unset (the test environments), Address(null) throws,
        // and the transport's default from is the right answer anyway.
        $from = config('mail.from.address');

        return new Envelope(
            from: $from ? new Address($from, config('mail.from.name')) : null,
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $this->purchaseOrder->loadMissing('supplier');

        return $this->bodyContent('procurement.quote_accepted', 'notifications.markdown.purchase-order-quote-acceptance', [
            'order' => $this->purchaseOrder,
            'reference' => $this->purchaseOrder->po_number,
            'quote' => $this->purchaseOrder->quote_number,
            'supplier' => $this->purchaseOrder->supplier,
            'total' => $this->purchaseOrder->vendorTotal(),
        ]);
    }
}
