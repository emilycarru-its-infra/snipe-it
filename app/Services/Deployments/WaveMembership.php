<?php

namespace App\Services\Deployments;

use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentWave;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which wave a person belongs to, and whether they belong there.
 *
 * Two separate questions the program used to answer by eye.
 *
 * **Which wave invited them.** A wave announces to twenty faculty and some of
 * them order. Nothing tied the order back to the wave, so "who from wave 2 has
 * actually ordered" meant reading two screens and comparing names. An order
 * stamped with the wave it came from answers it in a query.
 *
 * **Whether they are eligible.** The announcement mails whoever holds a device in
 * the wave; the form checks group membership; and nothing compared the two. A
 * faculty member in the wave whose laptop is not at lease end is usually an
 * exception somebody made on purpose — a broken machine, a new hire mid-cycle —
 * so this warns and never blocks. The point is that the exception is visible
 * rather than discovered next year.
 */
class WaveMembership
{
    /**
     * The wave a person's faculty order belongs to: the most recently announced
     * wave holding a device of theirs. Announced, because an order placed today
     * answers the invitation that went out, not a wave still being planned.
     */
    public function waveFor(User $user): ?DeploymentWave
    {
        $assetIds = Asset::where('assigned_type', User::class)
            ->where('assigned_to', $user->id)
            ->pluck('id');

        if ($assetIds->isEmpty()) {
            return null;
        }

        $waveIds = DeploymentItem::query()
            ->where(fn ($q) => $q->whereIn('asset_id', $assetIds)->orWhereIn('replaces_asset_id', $assetIds))
            ->pluck('wave_id')
            ->unique();

        if ($waveIds->isEmpty()) {
            return null;
        }

        return DeploymentWave::whereIn('id', $waveIds)
            ->whereNotNull('announced_at')
            ->orderByDesc('announced_at')
            ->first();
    }

    /**
     * Wave members whose device is not actually at lease end.
     *
     * A warning, not a gate: exceptions are legitimate and the person adding one
     * knows why. What is not legitimate is finding out in March that somebody was
     * refreshed a year early because a row was added to the wrong wave.
     *
     * @return Collection<int, array{user: User, assets: Collection<int, Asset>, reason: string}>
     */
    public function ineligible(DeploymentWave $wave, ?Collection $recipients = null): Collection
    {
        $recipients ??= (new WaveAnnouncer)->recipients($wave);
        $cutoff = now()->addMonths(self::LEASE_END_WINDOW_MONTHS);

        $flagged = collect();

        foreach ($recipients as $row) {
            $user = $row['user'];
            $assets = $row['assets'];

            // Any device of theirs in the wave reaching lease end inside the
            // window makes them eligible; the reason names what was missing when
            // none does.
            $ending = $assets->filter(fn (Asset $asset) => $asset->lease_end_date !== null
                && $asset->lease_end_date <= $cutoff);

            if ($ending->isNotEmpty()) {
                continue;
            }

            $hasAnyLeaseDate = $assets->contains(fn (Asset $asset) => $asset->lease_end_date !== null);

            $flagged->push([
                'user' => $user,
                'assets' => $assets,
                'reason' => $hasAnyLeaseDate ? 'lease_not_ending' : 'no_lease_end_date',
            ]);
        }

        return $flagged->values();
    }

    /**
     * How far ahead a lease still counts as ending. A quarter past the wave's own
     * year would be arguing; twelve months matches the refresh forecast, which is
     * where these cohorts are drawn from in the first place.
     */
    public const LEASE_END_WINDOW_MONTHS = 12;
}
