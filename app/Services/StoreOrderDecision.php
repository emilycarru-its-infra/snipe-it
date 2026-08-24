<?php

namespace App\Services;

use App\Models\StoreOrder;
use App\Services\CdwAccounts;

/**
 * Approving or declining a store order, in one place.
 *
 * The decision has three consequences that must always travel together: the
 * order's own state, the fate of the assets provisioned for it at
 * submission, and the email the requester is waiting on. They used to live
 * inside the queue controller, which was fine while the queue was the only
 * door. It is not — the API decides too, and a decision that skipped the
 * asset release or the notification depending on which door it came through
 * would be a silent divergence nobody notices until a declined order's
 * placeholder turns up in a count months later.
 */
class StoreOrderDecision
{
    /**
     * Record the decision. The caller has already established that the
     * decider is allowed and that the order is still pending.
     *
     * @param  array<string, mixed>  $data
     */
    public function decide(StoreOrder $order, array $data): StoreOrder
    {
        $order->update([
            'status' => $data['decision'],
            'decision_notes' => $data['decision_notes'] ?? null,
            'funding_account' => $data['funding_account'] ?? null,
            // Only a lease rides a schedule; carrying one on a purchase
            // would put a reference in the CSV that means nothing there.
            // Asked of CdwAccounts rather than compared to 'lease': the
            // stored values are lease_admin and lease_curriculum, so a
            // string match on 'lease' is false for every real lease account
            // and quietly dropped the schedule off all of them.
            'lease_schedule' => CdwAccounts::needsSchedule($data['funding_account'] ?? null)
                ? ($data['lease_schedule'] ?: null)
                : null,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        if ($data['decision'] === 'declined') {
            // A declined order's provisioned assets will never arrive.
            app(StoreOrderAssetProvisioner::class)->release($order);
        }

        StoreOrderNotifier::requester($order, $data['decision']);

        return $order;
    }
}
