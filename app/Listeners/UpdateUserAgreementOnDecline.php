<?php

namespace App\Listeners;

use App\Events\CheckoutDeclined;
use App\Models\UserAgreement;

/**
 * A declined signing request puts the agreement back to Generated with
 * the decline noted, so reminders stop and staff can follow up and send
 * it again. Left at Sent, it kept emailing someone who had said no and
 * could never be re-sent, because a request already existed.
 */
class UpdateUserAgreementOnDecline
{
    public function handle(CheckoutDeclined $event): void
    {
        $acceptance = $event->acceptance;
        if (! $acceptance || ! $acceptance->id) {
            return;
        }

        $agreement = UserAgreement::where('checkout_acceptance_id', $acceptance->id)->first();
        if (! $agreement) {
            return;
        }

        $line = trans('admin/user-agreements/general.declined_note', [
            'date' => now()->toDateString(),
            'note' => $acceptance->note ?: '—',
        ]);

        $agreement->lifecycle_stage = 'quoted';
        $agreement->checkout_acceptance_id = null;
        $agreement->notes = trim(($agreement->notes ? $agreement->notes."\n" : '').$line);
        $agreement->saveQuietly();
    }
}
