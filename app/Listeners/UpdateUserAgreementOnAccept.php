<?php

namespace App\Listeners;

use App\Events\CheckoutAccepted;
use App\Models\UserAgreement;

/**
 * When Snipe's native acceptance flow records a signature, propagate the
 * signed-at and stored-PDF path onto any UserAgreement that pointed
 * at this acceptance. The agreement's lifecycle moves from
 * agreement_sent → agreement_signed.
 */
class UpdateUserAgreementOnAccept
{
    public function handle(CheckoutAccepted $event): void
    {
        $acceptance = $event->acceptance;
        if (! $acceptance || ! $acceptance->id) {
            return;
        }

        $agreement = UserAgreement::where('checkout_acceptance_id', $acceptance->id)->first();
        if ($agreement) {
            $agreement->markSigned($acceptance);
        }
    }
}
