<?php

namespace App\Services\Leasing;

use Illuminate\Support\Facades\DB;

/**
 * Keeps `ownership_type` in step with the buyout statuses.
 *
 * Buyouts were recorded for years by inventing statuses — "Active (Buyouts)",
 * "Active (Legacy)", and an archived "Purchased" — because there was no better
 * lever. `ownership_type` is now the authoritative answer to whether a unit is
 * still on its lease, but people will keep reaching for the status they have
 * always used until the taxonomy is retired, and every record set that way
 * would otherwise leave its lease open forever.
 *
 * So this runs nightly as a bridge: where a status asserts the unit came off
 * the lease and ownership has not caught up, ownership is corrected. It only
 * ever moves a leased asset *to* Purchased and never the other way, so it
 * cannot undo a deliberate change. Once the statuses are gone it quietly
 * becomes a no-op rather than something else to remember to remove.
 *
 * Deliberately in-app rather than in the inventory-self-tidy function: that
 * exists to reconcile Snipe against outside systems (Intune, TDX, Entra) over
 * the API, whereas this is one table agreeing with itself.
 */
class LeaseOwnershipReconciler
{
    /**
     * The one status that asserts ECU bought the unit out of its lease.
     *
     * Its own note is unambiguous — "Assets bought out of their leases that are
     * still in use and aging" — and it is only ever set after a lease ends and
     * the buyout is decided, so it maps exactly onto `ownership_type` becoming
     * Purchased.
     *
     * Two neighbours deliberately excluded, both on the strength of their notes:
     *
     *   - "Active (Legacy)" — "past their end of life date without replacement
     *     due to buyouts or inability to replace". A replacement-planning
     *     marker that applies equally to kit bought outright and kit leased
     *     years ago, so it says nothing about ownership. 73 of its 85 assets
     *     carry no lease at all.
     *   - "Purchased" — "purchased by faculty". The Faculty Program, not an ECU
     *     buyout. The unit has left the lease, so the lease still closes (the
     *     status is archived and LeaseClosure handles it there), but ECU does
     *     not own it and claiming Purchased ownership would say we do.
     */
    public const OFF_LEASE_STATUSES = ['Active (Buyouts)'];

    private const OWNERSHIP_PURCHASED = 'Purchased';

    /**
     * @return array{candidates:int, written:int, by_status:array<string,int>}
     */
    public function run(bool $write): array
    {
        $statuses = DB::table('status_labels')
            ->whereIn('name', self::OFF_LEASE_STATUSES)
            ->pluck('name', 'id');

        if ($statuses->isEmpty()) {
            return ['candidates' => 0, 'written' => 0, 'by_status' => []];
        }

        $rows = DB::table('assets')
            ->whereNull('deleted_at')
            ->whereNotNull('lease_contract_id')
            ->where('lease_contract_id', '!=', '')
            ->whereIn('status_id', $statuses->keys())
            ->where(fn ($q) => $q->whereNull('ownership_type')->orWhere('ownership_type', '!=', self::OWNERSHIP_PURCHASED))
            ->get(['id', 'status_id']);

        $byStatus = [];
        foreach ($rows as $row) {
            $name = (string) $statuses->get($row->status_id);
            $byStatus[$name] = ($byStatus[$name] ?? 0) + 1;
        }
        ksort($byStatus);

        $written = 0;
        if ($write && $rows->isNotEmpty()) {
            // Straight through the query builder: ownership is derived here, so
            // this must not fire asset observers or land in asset history.
            $written = DB::table('assets')
                ->whereIn('id', $rows->pluck('id'))
                ->update(['ownership_type' => self::OWNERSHIP_PURCHASED]);
        }

        return [
            'candidates' => $rows->count(),
            'written' => $written,
            'by_status' => $byStatus,
        ];
    }
}
