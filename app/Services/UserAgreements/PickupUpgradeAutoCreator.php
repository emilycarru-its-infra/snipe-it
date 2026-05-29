<?php

namespace App\Services\UserAgreements;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\FormAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Auto-create `pickup` and (when over base) `upgrade` UserAgreement
 * rows when a new laptop is checked out to a user who:
 *   - is eligible to submit the faculty intake form, AND
 *   - already has another asset whose linked contract end_date is
 *     within `config('forms.pickup_auto_create.lease_end_within_months')`
 *
 * Driven by AssetObserver::updated when `assigned_to` changes from
 * null/other to a User. Same idempotency guarantees as the purchase
 * auto-creator: every call is safe to repeat; an open pickup row for
 * the same (user, new asset) pair blocks duplicate creation.
 *
 * Cost math:
 *   base    = config('forms.pickup_auto_create.base_program_price')
 *   device  = newAsset.purchase_cost (0 if missing)
 *   top_up  = max(0, device - base)
 *   pickup  always created with base_program_price + device_cost
 *   upgrade created only when top_up > 0, with top_up_amount set
 */
class PickupUpgradeAutoCreator
{
    public function __construct(private readonly CostResolver $costs)
    {
    }

    public function __construct(private readonly CostResolver $costs)
    {
    }

    /** @return array{pickup: ?UserAgreement, upgrade: ?UserAgreement} */
    public function ensureForCheckout(Asset $newAsset): array
    {
        $none = ['pickup' => null, 'upgrade' => null];

        if (! (bool) config('forms.pickup_auto_create.enabled', true)) {
            return $none;
        }

        $userId = $this->resolveUserId($newAsset);
        if (! $userId) {
            return $none;
        }

        $user = User::find($userId);
        if (! $user) {
            return $none;
        }

        if (! $this->isFacultyEligible($user)) {
            return $none;
        }

        if (! $this->hasOtherAssetNearingLeaseEnd($user, $newAsset)) {
            return $none;
        }

        $base   = $this->costs->baseProgramPrice() ?? 0.0;
        $device = $this->costs->deviceCost($newAsset) ?? 0.0;
        $topUp  = $this->costs->topUpAmount($newAsset, $device, $base) ?? 0.0;

        $pickup  = $this->ensurePickup($user, $newAsset, $base, $device);
        $upgrade = $topUp > 0
            ? $this->ensureUpgrade($user, $newAsset, $base, $device, $topUp)
            : null;

        Log::info('pickup/upgrade auto-create complete', [
            'user_id'         => $user->id,
            'asset_id'        => $newAsset->id,
            'pickup_id'       => $pickup?->id,
            'upgrade_id'      => $upgrade?->id,
            'base_program_price' => $base,
            'device_cost'     => $device,
            'top_up_amount'   => $topUp,
        ]);

        return ['pickup' => $pickup, 'upgrade' => $upgrade];
    }

    private function resolveUserId(Asset $asset): ?int
    {
        if ($asset->assigned_type !== User::class) {
            return null;
        }
        return $asset->assigned_to ? (int) $asset->assigned_to : null;
    }

    /**
     * Single source of truth for "is this user a faculty member?" —
     * delegates to FormAccess::canSubmit so the auto-create gate
     * stays aligned with whatever eligibility logic the intake form
     * uses. Re-deriving the query here would silently drift if
     * FormAccess ever grows new rules (per-company gating, expiry,
     * etc).
     */
    private function isFacultyEligible(User $user): bool
    {
        $slug = (string) config('forms.pickup_auto_create.eligibility_form_slug', 'faculty-program');
        return FormAccess::canSubmit($user, $slug);
    }

    /**
     * True if this user has any asset *other than* the new one whose
     * linked contract end_date is in the future AND no more than
     * `lease_end_within_months` away. Without a lower bound, a
     * contract that ended months or years ago would still count as
     * "nearing lease end" — auto-creating a fresh pickup for a stale
     * record. The window matches `Contract::scopeExpiringWithin`.
     */
    private function hasOtherAssetNearingLeaseEnd(User $user, Asset $newAsset): bool
    {
        $now    = Carbon::now();
        $cutoff = $now->copy()->addMonths(
            (int) config('forms.pickup_auto_create.lease_end_within_months', 6)
        );

        $otherAssetIds = Asset::where('assigned_type', User::class)
            ->where('assigned_to', $user->id)
            ->where('id', '!=', $newAsset->id)
            ->pluck('id')
            ->all();

        if (empty($otherAssetIds)) {
            return false;
        }

        return Contract::query()
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$now->toDateString(), $cutoff->toDateString()])
            ->whereHas('assets', fn ($q) => $q->whereIn('assets.id', $otherAssetIds))
            ->exists();
    }

    private function ensurePickup(User $user, Asset $asset, float $base, float $device): UserAgreement
    {
        $existing = UserAgreement::query()
            ->where('user_id', $user->id)
            ->where('asset_id', $asset->id)
            ->where('agreement_type', 'pickup')
            ->whereIn('lifecycle_stage', UserAgreement::OPEN_LIFECYCLE_STAGES)
            ->first();

        if ($existing) {
            return $existing;
        }

        return UserAgreement::create([
            'agreement_type'     => 'pickup',
            'user_id'            => $user->id,
            'asset_id'           => $asset->id,
            'lifecycle_stage'    => 'quoted',
            'base_program_price' => $base ?: null,
            'device_cost'        => $device ?: null,
        ]);
    }

    private function ensureUpgrade(User $user, Asset $asset, float $base, float $device, float $topUp): UserAgreement
    {
        $existing = UserAgreement::query()
            ->where('user_id', $user->id)
            ->where('asset_id', $asset->id)
            ->where('agreement_type', 'upgrade')
            ->whereIn('lifecycle_stage', UserAgreement::OPEN_LIFECYCLE_STAGES)
            ->first();

        if ($existing) {
            return $existing;
        }

        return UserAgreement::create([
            'agreement_type'     => 'upgrade',
            'user_id'            => $user->id,
            'asset_id'           => $asset->id,
            'lifecycle_stage'    => 'quoted',
            'base_program_price' => $base ?: null,
            'device_cost'        => $device ?: null,
            'top_up_amount'      => $topUp,
        ]);
    }
}
