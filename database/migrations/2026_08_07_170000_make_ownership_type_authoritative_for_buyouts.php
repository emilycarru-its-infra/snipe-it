<?php

use App\Services\Leasing\LeaseOwnershipReconciler;
use Illuminate\Database\Migrations\Migration;

/**
 * Make `ownership_type` the single answer to "is this unit still on the lease".
 *
 * Buyouts were recorded by inventing statuses — "Active (Buyouts)" and
 * "Active (Legacy)" — because there was no other lever at the time. That
 * overloads one field with two unrelated questions (where the device is, and
 * who owns it) and it drifted: 23 leased units carry a status asserting they
 * are off the lease while `ownership_type` still reads "Lease to Return".
 *
 * `ownership_type` is the better home and is already complete — every one of
 * the 1,340 leased assets carries one (1,111 Lease to Return, 197 Lease to Own,
 * 32 Purchased) — whereas the statuses are a partial overlay. "Active (Legacy)"
 * is not even lease-specific: 73 of its 85 assets are not leased at all, so
 * reading it as a lease signal was wrong in the general case.
 *
 * This backfills ownership from the status where the status is the more
 * up-to-date of the two. It does not retire the statuses: assets keep them, and
 * removing them from the taxonomy is a separate decision covered by its own
 * work item, alongside the New (*) statuses the Orders Pipeline now supersedes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The same reconcile that now runs nightly, applied once to the records
        // that drifted before it existed.
        app(LeaseOwnershipReconciler::class)->run(true);
    }

    /**
     * Not reversible in any meaningful way: the previous value was "Lease to
     * Return", which was simply wrong for these units, and restoring it would
     * reintroduce the drift. The statuses that carry the same fact are
     * untouched, so nothing is lost by leaving this in place.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
