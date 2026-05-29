<?php

namespace App\Services\UserAgreements;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\FormAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * State-based reconciliation for the UserAgreement Program.
 *
 * The event-driven auto-creators in AssetObserver only fire on the
 * status flip / fresh checkout moment. Anything assigned or
 * lease-ended before those PRs landed (or any future case where the
 * event was missed) stays in a broken state — no pickup row, no
 * upgrade row, no purchase row. This service is the opposite: it
 * looks at *current* state and ensures the rows that should exist
 * actually do.
 *
 * Per faculty user, for every assigned asset:
 *
 *   - missing `pickup`   row → create
 *   - device_cost > base_program_price AND missing `upgrade` row → create
 *   - linked contract end_date <= today AND missing `purchase` row → create
 *   - linked contract ended AND status != configured lease-end label → flip status
 *
 * Idempotent — an open agreement of the right type for (user, asset)
 * blocks re-creation, and the status flip uses wasChanged() on save
 * so a no-op pass writes nothing.
 *
 * Used by:
 *   - `snipeit:user-agreements-reconcile` (nightly + on-demand)
 *   - Future per-user "refresh" button in the admin UI
 */
class Reconciler
{
    public function __construct(private readonly CostResolver $costs)
    {
    }

    /**
     * Walk every faculty-eligible user with at least one assigned
     * asset and reconcile them. Cheap because most users will produce
     * zero changes after the first run.
     *
     * @return array<int, ReconciliationReport>
     */
    public function reconcileAll(bool $dryRun = false): array
    {
        $reports = [];
        foreach ($this->facultyUsersWithAssets() as $user) {
            $reports[] = $this->reconcileForUser($user, $dryRun);
        }
        return $reports;
    }

    public function reconcileForUser(User $user, bool $dryRun = false): ReconciliationReport
    {
        $report = new ReconciliationReport($user->id);

        foreach ($this->assignedAssets($user) as $asset) {
            $this->reconcilePickup($user, $asset, $report, $dryRun);
            $this->reconcileUpgrade($user, $asset, $report, $dryRun);
            $this->reconcilePurchase($user, $asset, $report, $dryRun);
            $this->reconcileStatus($asset, $report, $dryRun);
        }

        return $report;
    }

    private function reconcilePickup(User $user, Asset $asset, ReconciliationReport $report, bool $dryRun): void
    {
        if ($this->hasOpenAgreement($user, $asset, 'pickup')) {
            return;
        }

        if ($dryRun) {
            $report->plannedPickup++;
            return;
        }

        $base   = $this->costs->baseProgramPrice();
        $device = $this->costs->deviceCost($asset);

        $row = UserAgreement::create([
            'agreement_type'     => 'pickup',
            'user_id'            => $user->id,
            'asset_id'           => $asset->id,
            'lifecycle_stage'    => 'quoted',
            'base_program_price' => $base,
            'device_cost'        => $device,
        ]);

        $report->createdPickup++;
        $report->createdRowIds[] = $row->id;
    }

    private function reconcileUpgrade(User $user, Asset $asset, ReconciliationReport $report, bool $dryRun): void
    {
        $topUp = $this->costs->topUpAmount($asset);

        // No top-up means the device is at or below the base — no
        // upgrade row needed. Null = unknown costs, treat the same.
        if ($topUp === null || $topUp <= 0) {
            return;
        }

        if ($this->hasOpenAgreement($user, $asset, 'upgrade')) {
            return;
        }

        if ($dryRun) {
            $report->plannedUpgrade++;
            return;
        }

        $row = UserAgreement::create([
            'agreement_type'     => 'upgrade',
            'user_id'            => $user->id,
            'asset_id'           => $asset->id,
            'lifecycle_stage'    => 'quoted',
            'base_program_price' => $this->costs->baseProgramPrice(),
            'device_cost'        => $this->costs->deviceCost($asset),
            'top_up_amount'      => $topUp,
        ]);

        $report->createdUpgrade++;
        $report->createdRowIds[] = $row->id;
    }

    private function reconcilePurchase(User $user, Asset $asset, ReconciliationReport $report, bool $dryRun): void
    {
        if (! $this->leaseHasEnded($asset)) {
            return;
        }

        if ($this->hasOpenAgreement($user, $asset, 'purchase')) {
            return;
        }

        if ($dryRun) {
            $report->plannedPurchase++;
            return;
        }

        $row = UserAgreement::create([
            'agreement_type'  => 'purchase',
            'user_id'         => $user->id,
            'asset_id'        => $asset->id,
            'lifecycle_stage' => 'quoted',
            'buyout_cost'     => $this->costs->buyoutCost($asset),
            'old_asset_tag'   => $asset->asset_tag,
            'old_serial'      => $asset->serial,
        ]);

        $report->createdPurchase++;
        $report->createdRowIds[] = $row->id;
    }

    /**
     * If the asset's lease has ended in reality but Snipe's status
     * label hasn't been flipped, flip it. Uses saveQuietly so we
     * don't re-trigger AssetObserver (the purchase row we may have
     * just created already covers what the observer would do).
     */
    private function reconcileStatus(Asset $asset, ReconciliationReport $report, bool $dryRun): void
    {
        if (! $this->leaseHasEnded($asset)) {
            return;
        }

        $targetLabels = (array) config('forms.purchase_auto_create.lease_end_status_labels', []);
        if (empty($targetLabels)) {
            return;
        }

        $currentName = $asset->status_id
            ? optional(Statuslabel::find($asset->status_id))->name
            : null;

        if ($currentName && in_array($currentName, $targetLabels, true)) {
            return;
        }

        $target = Statuslabel::whereIn('name', $targetLabels)->orderBy('id')->first();
        if (! $target) {
            Log::warning('reconciler: configured lease-end Statuslabel not found', [
                'asset_id' => $asset->id,
                'looking_for' => $targetLabels,
            ]);
            return;
        }

        if ($dryRun) {
            $report->plannedStatusFlip++;
            return;
        }

        $asset->status_id = $target->id;
        $asset->saveQuietly();
        $report->statusFlipped++;
    }

    private function hasOpenAgreement(User $user, Asset $asset, string $type): bool
    {
        return UserAgreement::query()
            ->where('user_id', $user->id)
            ->where('asset_id', $asset->id)
            ->where('agreement_type', $type)
            ->whereIn('lifecycle_stage', UserAgreement::OPEN_LIFECYCLE_STAGES)
            ->exists();
    }

    /**
     * True when this asset has at least one linked contract whose
     * end_date is on or before today. Assets without any contract
     * link return false — without a date we can't claim the lease
     * has ended.
     */
    private function leaseHasEnded(Asset $asset): bool
    {
        $today = Carbon::now()->toDateString();

        return Contract::query()
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $today)
            ->whereHas('assets', fn ($q) => $q->where('assets.id', $asset->id))
            ->exists();
    }

    /**
     * @return iterable<Asset>
     */
    private function assignedAssets(User $user): iterable
    {
        return Asset::query()
            ->where('assigned_type', User::class)
            ->where('assigned_to', $user->id)
            ->get();
    }

    /**
     * Users in any group bound to the configured intake form. Same
     * gate the intake form uses, so the operator manages the cohort
     * in one place.
     *
     * @return iterable<User>
     */
    private function facultyUsersWithAssets(): iterable
    {
        $slug     = (string) config('forms.pickup_auto_create.eligibility_form_slug', 'faculty-program');
        $groupIds = \App\Models\FormEligibility::where('form_slug', $slug)->pluck('group_id')->all();

        if (empty($groupIds)) {
            return [];
        }

        return User::query()
            ->whereHas('groups', fn ($q) => $q->whereIn('permission_groups.id', $groupIds))
            ->whereHas('assets')
            ->get();
    }
}
