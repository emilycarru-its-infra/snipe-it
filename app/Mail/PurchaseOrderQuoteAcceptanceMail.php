<?php

namespace App\Mail;

use App\Models\PurchaseOrder;

/**
 * Our acceptance of the vendor's final quote: place it.
 *
 * The same email as the order request — the lines in a table, the part list
 * as a CSV, the purchase order attached — because that is what their desk
 * keys the order from, and an acceptance that summarised instead would send
 * them back to the request to find the lines. What changes is the money and
 * the ask: the unit prices are theirs, from the quote, and the wording says
 * "place" rather than "please quote". See {@see RequisitionVendorOrderMail}.
 */
class PurchaseOrderQuoteAcceptanceMail extends RequisitionVendorOrderMail
{
    public function __construct(PurchaseOrder $purchaseOrder)
    {
        parent::__construct($purchaseOrder, test: false, accepted: true);
    }
}
