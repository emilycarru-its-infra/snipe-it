<?php

namespace App\Services\UserAgreements;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\FormEligibility;
use App\Models\User;
use App\Models\UserAgreement;
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
    private const OPEN_STAGES = ['eligible', 'quoted', 'agreement_sent', 'agreement_signed', 'deployed', 'in_repayment'];

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

        $base   = (float) config('forms.pickup_auto_create.base_program_price', 0);
        $device = $newAsset->purchase_cost ? (float) $newAsset->purchase_cost : 0.0;
        $topUp  = max(0.0, $device - $base);

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

    private function isFacultyEligible(User $user): bool
    {
        $slug = (string) config('forms.pickup_auto_create.eligibility_form_slug', 'faculty-program');

        $groupIds = FormEligibility::where('form_slug', $slug)->pluck('group_id')->all();
        if (empty($groupIds)) {
            return false;
        }

        return $user->groups()->whereIn('permission_groups.id', $groupIds)->exists();
    }

    /**
     * True if this user has any asset *other than* the new one whose
     * earliest linked contract end_date falls within the configured
     * window. Older Snipe data with no contract bridge fails the check,
     * which is intentional — without an end_date we can't justify
     * auto-creating a pickup.
     */
    private function hasOtherAssetNearingLeaseEnd(User $user, Asset $newAsset): bool
    {
        $cutoff = Carbon::now()->addMonths(
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
            ->where('end_date', '<=', $cutoff)
            ->whereHas('assets', fn ($q) => $q->whereIn('assets.id', $otherAssetIds))
            ->exists();
    }

    private function ensurePickup(User $user, Asset $asset, float $base, float $device): UserAgreement
    {
        $existing = UserAgreement::query()
            ->where('user_id', $user->id)
            ->where('asset_id', $asset->id)
            ->where('agreement_type', 'pickup')
            ->whereIn('lifecycle_stage', self::OPEN_STAGES)
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
            ->whereIn('lifecycle_stage', self::OPEN_STAGES)
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
