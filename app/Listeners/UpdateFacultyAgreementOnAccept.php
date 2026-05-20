<?php

namespace App\Listeners;

use App\Events\CheckoutAccepted;
use App\Models\FacultyAgreement;

/**
 * When Snipe's native acceptance flow records a signature, propagate the
 * signed-at and stored-PDF path onto any FacultyAgreement that pointed
 * at this acceptance. The agreement's lifecycle moves from
 * agreement_sent → agreement_signed.
 */
class UpdateFacultyAgreementOnAccept
{
    public function handle(CheckoutAccepted $event): void
    {
        $acceptance = $event->acceptance;
        if (! $acceptance || ! $acceptance->id) {
            return;
        }

        $agreement = FacultyAgreement::where('checkout_acceptance_id', $acceptance->id)->first();
        if ($agreement) {
            $agreement->markSigned($acceptance);
        }
    }
}
