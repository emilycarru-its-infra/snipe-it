<?php

namespace App\Services\Leasing;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Models\AssetBuyoutQuote;
use App\Models\Statuslabel;
use App\Models\User;

/**
 * Moves a buyout along its lifecycle, and owns the two side effects that a
 * plain status column cannot express: mirroring the live quote onto the
 * parent row, and — on completion — parking the asset on the archived
 * "Purchased" status, which is what tells the management systems to drop
 * the device.
 *
 * Everything here writes to the asset's activity timeline as well, so the
 * buyout reads in one place on the board and in another on the device.
 */
class BuyoutTracker
{
    /**
     * The archived status a completed buyout lands the asset on. Resolved by
     * name rather than id: status labels are data, not schema, and this one
     * already exists on prod. If it is missing the buyout still completes —
     * losing the record because a label was renamed would be worse than a
     * device left on the wrong status.
     *
     * Note which "Purchased" this is. The archived status "Purchased" means
     * *purchased by faculty*, which is exactly what every buyout tracked here
     * is: a departing employee buying their own laptop. That is why completing
     * one deliberately does NOT set `ownership_type = 'Purchased'` — that
     * column means ECU bought the unit off its lease and still owns it, which
     * is the opposite claim. `LeaseOwnershipReconciler` excludes this status
     * from its bridge for the same reason; the lease still closes because
     * `LeaseClosure` reads the archived status. Do not "fix" this by adding an
     * ownership write.
     *
     * An ECU-side buyout, where ECU keeps the device, is the separate
     * "Active (Buyouts)" path and is not what this tracker records.
     */
    public function completedStatus(): ?Statuslabel
    {
        return Statuslabel::where('name', config('leasing.buyout_completed_status', 'Purchased'))
            ->where('archived', 1)
            ->first();
    }

    /**
     * Open the record for a freshly mailed quote request, or re-stamp the one
     * already open for this asset. A second request about the same device is
     * a chase, not a second buyout.
     */
    public function opened(Asset $asset, ?User $requester): AssetBuyout
    {
        $buyout = AssetBuyout::where('asset_id', $asset->id)->open()->latest('id')->first();

        if ($buyout) {
            $buyout->forceFill(['requested_at' => now()])->save();

            return $buyout;
        }

        return AssetBuyout::create([
            'asset_id' => $asset->id,
            'lessor_id' => $asset->lessor_id,
            // The person the device is checked out to is the buyer in every
            // case so far — these start when somebody is leaving.
            'buyer_id' => ($asset->assignedTo instanceof User) ? $asset->assignedTo->id : null,
            'requested_by' => $requester?->id,
            'status' => 'requested',
            'requested_at' => now(),
        ]);
    }

    /**
     * Record what the lessor came back with. The newest quote is the live one;
     * earlier ones stay on the record because a superseded figure is exactly
     * what people keep quoting back from the mail thread.
     */
    public function recordQuote(AssetBuyout $buyout, array $data, ?User $actor): AssetBuyoutQuote
    {
        $amount = $data['quote_amount'] !== null ? (float) $data['quote_amount'] : null;
        $rent = $data['remaining_rent'] !== null ? (float) $data['remaining_rent'] : null;
        $total = ($amount ?? 0) + ($rent ?? 0);

        $quote = $buyout->quotes()->create([
            'quote_amount' => $amount,
            'remaining_rent' => $rent,
            'quote_total' => $total,
            'quoted_at' => $data['quoted_at'] ?? now()->toDateString(),
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $actor?->id,
        ]);

        // The split defaults to the buyer carrying the whole total. There is
        // no rule to derive it from — the precedent of charging only the
        // buyout price and absorbing the remaining rent has been applied case
        // by case, so it is typed in, not computed.
        $buyout->forceFill([
            'status' => $buyout->status === 'requested' ? 'quoted' : $buyout->status,
            'quote_amount' => $amount,
            'remaining_rent' => $rent,
            'quote_total' => $total,
            'quoted_at' => $quote->quoted_at,
            'buyer_amount' => $buyout->buyer_amount ?? $total,
            'ecu_amount' => $buyout->ecu_amount ?? 0,
        ])->save();

        $this->log($buyout, trans('admin/deployments/general.buyout_log_quoted', [
            'total' => number_format($total, 2),
        ]), $actor);

        return $quote;
    }

    /**
     * Advance to a named status, stamping the timestamp that status owns.
     * `$data` carries only what the target status needs — the controller has
     * already validated it.
     */
    public function transition(AssetBuyout $buyout, string $status, array $data, ?User $actor): void
    {
        $fields = ['status' => $status];

        switch ($status) {
            case 'approved':
                $fields['approved_at'] = now();
                $fields['approved_by'] = $actor?->id;
                break;

            case 'declined':
                $fields['declined_at'] = now();
                $fields['decline_reason'] = $data['decline_reason'] ?? null;
                break;

            case 'invoiced':
                $fields['invoice_number'] = $data['invoice_number'] ?? null;
                $fields['invoice_date'] = $data['invoice_date'] ?? null;
                $fields['invoice_due_date'] = $data['invoice_due_date'] ?? null;
                break;

            case 'paid':
                $fields['paid_at'] = $data['paid_at'] ?? now();
                $fields['payment_method'] = $data['payment_method'] ?? null;
                $fields['payment_reference'] = $data['payment_reference'] ?? null;
                break;

            case 'completed':
                $fields['completed_at'] = now();
                break;
        }

        $buyout->forceFill($fields)->save();

        if ($status === 'completed') {
            $this->releaseAsset($buyout);
        }

        $this->log($buyout, trans('admin/deployments/general.buyout_log_status', [
            'status' => trans('admin/deployments/general.buyout_status_'.$status),
        ]), $actor);
    }

    /**
     * The device is the buyer's now: park it on the archived Purchased status
     * and stamp the decommission date, which is what drops it out of the
     * return pool and out of the management systems. The decommission date is
     * only set if it is empty — a hand-entered handover date outranks today.
     */
    private function releaseAsset(AssetBuyout $buyout): void
    {
        $asset = $buyout->asset;

        if (! $asset) {
            return;
        }

        $fields = [];

        if ($status = $this->completedStatus()) {
            $fields['status_id'] = $status->id;
        }

        if (blank($asset->decommission_date)) {
            $fields['decommission_date'] = ($buyout->completed_at ?? now())->toDateString();
        }

        if ($fields) {
            $asset->forceFill($fields)->save();
        }
    }

    private function log(AssetBuyout $buyout, string $note, ?User $actor): void
    {
        $asset = $buyout->asset;

        if (! $asset) {
            return;
        }

        $log = new Actionlog;
        $log->item_type = Asset::class;
        $log->item_id = $asset->id;
        $log->setAttribute('created_by', $actor?->id);
        $log->company_id = $asset->company_id;
        $log->note = $note;
        $log->logaction(ActionType::BuyoutRequested->value);
    }
}
