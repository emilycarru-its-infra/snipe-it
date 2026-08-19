<?php

namespace App\Services\UserAgreements;

use App\Models\Asset;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Support\Facades\Log;

/**
 * Auto-create a `purchase` UserAgreement row when an asset hits a
 * lease-end Statuslabel. Driven by AssetObserver::updated() — the
 * service itself is the idempotent boundary: every call is safe to
 * repeat. Use it from anywhere that has an Asset (the observer, a
 * one-off backfill command, a future nightly sweep).
 *
 * Skipped (with a log line, not an exception) when:
 *  - the asset's new status isn't in config('forms.purchase_auto_create.lease_end_status_labels')
 *  - the asset has no assigned user (assigned_type != User::class or assigned_to null)
 *  - an open purchase row already exists for this (user, asset) pair
 *
 * Cost: `buyout_cost` is read from `asset.purchase_cost` per the
 * 2026-05-28 decision that purchase_cost is the authoritative buyout
 * number for end-of-lease devices. PR #5 (cost-resolver) will
 * centralise that lookup.
 */
class PurchaseAutoCreator
{
    private const OPEN_STAGES = ['eligible', 'quoted', 'agreement_sent', 'agreement_signed', 'deployed', 'in_repayment'];

    public function __construct(private readonly CostResolver $costs)
    {
    }

    public function ensureFor(Asset $asset): ?UserAgreement
    {
        if (! $this->isLeaseEndStatus($asset)) {
            return null;
        }

        // Programme devices only. The buyout figure comes from the
        // programme's residual math, and letting any lease-end device
        // through once priced an old iPad at the MacBook residual.
        if (! UserAgreement::programCovers($asset)) {
            return null;
        }

        $userId = $this->resolveUserId($asset);
        if (! $userId) {
            Log::info('purchase auto-create skipped: no assigned user', [
                'asset_id'  => $asset->id,
                'asset_tag' => $asset->asset_tag,
            ]);
            return null;
        }

        $existing = UserAgreement::query()
            ->where('user_id', $userId)
            ->where('asset_id', $asset->id)
            ->where('agreement_type', 'purchase')
            ->whereIn('lifecycle_stage', self::OPEN_STAGES)
            ->first();

        if ($existing) {
            return $existing;
        }

        $agreement = UserAgreement::create([
            'agreement_type'  => 'purchase',
            'user_id'         => $userId,
            'asset_id'        => $asset->id,
            // Eligible, not quoted. A status label changing is not somebody
            // asking to buy their laptop, and `quoted` says we put a price
            // in front of them, which nobody did. Every surface that reads
            // these rows read that stage as a decision: the intake form
            // pre-selected "yes, buy it out", the store's order page told
            // people they were keeping a machine they had said nothing
            // about, and declining on the form cancelled the phantom offer
            // and stamped the member Cancelled in the ledger. Eligible is
            // what this actually is — a machine reaching lease end, and a
            // buyout nobody has decided on either way.
            'lifecycle_stage' => 'eligible',
            'buyout_cost'     => $this->costs->buyoutCost($asset),
            'old_asset_tag'   => $asset->asset_tag,
            'old_serial'      => $asset->serial,
        ]);

        Log::info('purchase auto-create: row created', [
            'agreement_id' => $agreement->id,
            'user_id'      => $userId,
            'asset_id'     => $asset->id,
            'buyout_cost'  => $agreement->buyout_cost,
        ]);

        return $agreement;
    }

    private function isLeaseEndStatus(Asset $asset): bool
    {
        $configured = (array) config('forms.purchase_auto_create.lease_end_status_labels', []);
        if (empty($configured)) {
            return false;
        }

        $statusId = $asset->status_id;
        if (! $statusId) {
            return false;
        }

        // Avoid a query when the relation is already loaded.
        $status = $asset->relationLoaded('assetstatus')
            ? $asset->assetstatus
            : Statuslabel::find($statusId);

        return $status && in_array($status->name, $configured, true);
    }

    private function resolveUserId(Asset $asset): ?int
    {
        if ($asset->assigned_type !== User::class) {
            return null;
        }

        return $asset->assigned_to ? (int) $asset->assigned_to : null;
    }
}
