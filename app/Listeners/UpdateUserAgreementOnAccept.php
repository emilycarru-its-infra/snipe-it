<?php

namespace App\Listeners;

use App\Events\CheckoutAccepted;
use App\Models\AssetBuyout;
use App\Models\UserAgreement;
use App\Services\Leasing\BuyoutTracker;

/**
 * When Snipe's native acceptance flow records a signature, propagate the
 * signed-at and stored-PDF path onto any UserAgreement that pointed
 * at this acceptance. The agreement's lifecycle moves from
 * agreement_sent → agreement_signed.
 *
 * A purchase agreement issued for a buyout then carries that buyout on: the
 * signature approves it and sends the signed copy to payroll.
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
        // A signature only counts from the person the agreement is for.
        if (! $agreement || (int) $acceptance->assigned_to_id !== (int) $agreement->user_id) {
            return;
        }

        if ($agreement->lifecycle_stage === 'cancelled') {
            return;
        }
        if (! $agreement->signed_at) {
            $agreement->markSigned($acceptance);
        }

        $buyout = AssetBuyout::where('user_agreement_id', $agreement->id)->latest('id')->first();
        // Only the buyer's own signature carries the buyout on.
        if ($buyout
            && (int) $acceptance->assigned_to_id === (int) $agreement->user_id
            && (int) $agreement->user_id === (int) $buyout->buyer_id) {
            $buyout->setRelation('agreement', $agreement);
            app(BuyoutTracker::class)->signed($buyout, $acceptance->assignedTo);
        }
    }
}
