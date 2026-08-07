<?php

namespace App\Services\Leasing;

use App\Models\Asset;

/**
 * Decides whether a lease has finished its lifecycle.
 *
 * A leased device leaves the lease in one of two ways, and either one ends the
 * obligation for that unit:
 *
 *   - it goes back to the lessor — a `decommission_date` plus an archived
 *     status ("Returned Lease End", "Recycled", "Donated", "Stolen"); or
 *   - we buy it out — `ownership_type` becomes "Purchased", so the asset stays
 *     on the books but the lease no longer covers it.
 *
 * When every unit has left, the lease is closed and there is nothing left to
 * watch, chase or pay. Nothing previously computed this: `decommission_date` is
 * a first-party column that no business logic read, `contracts.is_active` is
 * derived from term dates alone, and `workflow_status` is unset on every lease.
 * So a schedule returned in full two years ago still reported months of
 * "extension" — ECI20210601A, 23 of 23 returned with decommission dates, was
 * the worst row on the Extension Watch.
 *
 * A unit that is archived but carries no decommission date counts as gone (the
 * device is demonstrably out of service) while being reported separately, since
 * a missing date on a returned device is a paperwork gap worth seeing rather
 * than a reason to hold the whole lease open.
 */
class LeaseClosure
{
    /**
     * Statuses meaning the unit came off the lease and stayed with us — the
     * device is still in service (or archived as ours), but the lessor has no
     * further claim on it.
     *
     * Status is the signal, not `ownership_type`. The two disagree in practice
     * because status is what gets set operationally and ownership is not kept
     * up: production carries a unit at "Active (Buyouts)" whose ownership still
     * reads "Lease to Return", and eleven more at status "Purchased" with the
     * same stale ownership. Reading ownership alone missed all of them.
     */
    private const RETAINED_STATUSES = [
        'Active (Buyouts)',
        'Active (Legacy)',
        'Purchased',
    ];

    /** Ownership value meaning the unit was bought out — corroborating only. */
    private const OWNERSHIP_PURCHASED = 'Purchased';

    /**
     * Summarise one lease's assets.
     *
     * @param  iterable<Asset>  $assets
     * @return array{total:int, closed:int, open:int, returned:int, bought_out:int, archived_without_date:int, is_closed:bool, open_assets:array<int,Asset>}
     */
    public function summarise(iterable $assets): array
    {
        $total = $closed = $returned = $boughtOut = $archivedWithoutDate = 0;
        $openAssets = [];

        foreach ($assets as $asset) {
            $total++;

            $isArchived = (bool) $asset->status?->archived;
            $hasDecommission = ! empty($asset->decommission_date);
            $isBoughtOut = in_array(trim((string) $asset->status?->name), self::RETAINED_STATUSES, true)
                || trim((string) $asset->ownership_type) === self::OWNERSHIP_PURCHASED;

            // Retention wins over the returned test: a bought-out unit can also
            // carry a decommission date once it is eventually retired, and it
            // left the lease by purchase, not by going back.
            if ($isBoughtOut) {
                $closed++;
                $boughtOut++;

                continue;
            }

            if ($hasDecommission && $isArchived) {
                $closed++;
                $returned++;

                continue;
            }

            if ($isArchived) {
                // Out of service, so the lease can still close, but the missing
                // date is a gap the caller should be able to surface.
                $closed++;
                $archivedWithoutDate++;

                continue;
            }

            $openAssets[] = $asset;
        }

        return [
            'total' => $total,
            'closed' => $closed,
            'open' => count($openAssets),
            'returned' => $returned,
            'bought_out' => $boughtOut,
            'archived_without_date' => $archivedWithoutDate,
            'is_closed' => $total > 0 && $openAssets === [],
            'open_assets' => $openAssets,
        ];
    }
}
