<?php

namespace App\Services;

use App\Models\StoreOrder;
use App\Models\User;
use App\Services\SupplierAccounts;

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
 *
 * Approval is also the send. An approved order with an account has nothing
 * left to wait for, so it goes to the vendor in the same step rather than
 * sitting in an "approved — send to vendor" pile that someone has to clear
 * by hand. One still missing its account goes the moment the account is set.
 */
class StoreOrderDecision
{
    /**
     * Record the decision. The caller has already established that the
     * decider is allowed and that the order is still pending.
     *
     * @param  array<string, mixed>  $data
     * @return array{sent: bool, error: ?string, recipients: array<int, string>}|null
     *         the vendor send an approval triggered, or null when none was attempted
     */
    public function decide(StoreOrder $order, array $data): ?array
    {
        $order->update([
            'status' => $data['decision'],
            'decision_notes' => $data['decision_notes'] ?? null,
            'funding_account' => $data['funding_account'] ?? null,
            // Only a lease rides a schedule; carrying one on a purchase
            // would put a reference in the CSV that means nothing there.
            // Asked of SupplierAccounts rather than compared to 'lease': the
            // stored values are lease_admin and lease_curriculum, so a
            // string match on 'lease' is false for every real lease account
            // and quietly dropped the schedule off all of them.
            'lease_schedule' => SupplierAccounts::needsSchedule($data['funding_account'] ?? null)
                ? (($data['lease_schedule'] ?? null) ?: null)
                : null,
            'gl_code' => $this->glCode($data, $order),
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        if ($data['decision'] === 'declined') {
            // A declined order's provisioned assets will never arrive.
            app(StoreOrderAssetProvisioner::class)->release($order);
        }

        $vendor = $data['decision'] === 'approved' ? $this->sendIfReady($order) : null;

        // A send already tells the requester their order is with the vendor;
        // an "approved" email a second earlier would only be noise.
        if (! ($vendor['sent'] ?? false)) {
            StoreOrderNotifier::requester($order, $data['decision']);
        }

        return $vendor;
    }

    /**
     * Send an approved order to the vendor if nothing is holding it back:
     * it has an account (and for a lease, a schedule) and has not gone yet.
     * Called on approval and again whenever the account is set afterwards.
     *
     * @return array{sent: bool, error: ?string, recipients: array<int, string>}|null
     */
    public function sendIfReady(StoreOrder $order, ?User $actor = null): ?array
    {
        $order->refresh();

        if ($order->status !== 'approved' || $order->vendor_sent_at !== null || ! $order->readyForVendor()) {
            return null;
        }

        $actor ??= auth()->user();

        if (! $actor) {
            return null;
        }

        $order->load('items.catalogItem.supplier', 'user');
        $result = app(StoreVendorOrderDispatch::class)->send(collect([$order]), $actor);

        return ['sent' => $result['sent'], 'error' => $result['error'], 'recipients' => $result['recipients']];
    }

    /**
     * What to tell whoever clicked: whether the order went to the vendor,
     * is waiting on its account, or was approved but the email failed.
     * The level is "success" or "warning" so both doors can flash or
     * report it the same way.
     *
     * @param  array{sent: bool, error: ?string, recipients: array<int, string>}|null  $vendor
     * @return array{level: string, message: string}
     */
    public static function outcome(string $decision, ?array $vendor, string $prefix = 'queue_decided_'): array
    {
        if ($decision !== 'approved') {
            return ['level' => 'success', 'message' => trans('admin/store/general.'.$prefix.$decision)];
        }

        if ($vendor === null) {
            return ['level' => 'success', 'message' => trans('admin/store/general.'.$prefix.'approved')];
        }

        if ($vendor['sent']) {
            return ['level' => 'success', 'message' => trans('admin/store/general.'.$prefix.'approved_sent', ['emails' => implode(', ', $vendor['recipients'])])];
        }

        return ['level' => 'warning', 'message' => trans('admin/store/general.approved_send_failed', ['error' => $vendor['error']])];
    }

    /**
     * The GL a decision leaves on the order. A GL given with the decision
     * wins; otherwise the one the requester supplied stands.
     *
     * @param  array<string, mixed>  $data
     */
    private function glCode(array $data, StoreOrder $order): ?string
    {
        if (! array_key_exists('gl_code', $data)) {
            return $order->gl_code;
        }

        $gl = trim((string) $data['gl_code']);

        return $gl !== '' ? $gl : $order->gl_code;
    }
}
