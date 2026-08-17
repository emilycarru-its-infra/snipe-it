<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes deployment items that point at an asset somebody already deleted.
 *
 * The 2026_06_13_080000 backfill walked the legacy "New (*)" status labels
 * with `Asset::withTrashed()`, so every device that had been deleted while
 * still parked on an intake label came back as a tracked deployment item —
 * bulk-import cleanups, API_TEST/FULLTEST fixtures, a run of iMacs binned a
 * year earlier. They cannot be opened, checked out or advanced, because the
 * asset relation resolves to null for a trashed row, and they were the bulk
 * of what the storage view was listing.
 *
 * Scoped to items that carry nothing but the dead asset: no replaced device,
 * no order line, never deployed. Anything with a second thread attached is a
 * real plan whose incoming asset happens to have been deleted, and losing the
 * row would lose the plan — those are left for a human.
 */
class DropDeploymentItemsForDeletedAssets extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('deployment_items') || ! Schema::hasTable('assets')) {
            return;
        }

        DB::table('deployment_items')
            ->whereNotNull('asset_id')
            ->whereNull('replaces_asset_id')
            ->whereNull('order_item_id')
            ->whereNull('deployed_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('assets')
                ->whereColumn('assets.id', 'deployment_items.asset_id')
                ->whereNotNull('assets.deleted_at'))
            ->delete();
    }

    public function down()
    {
        // The rows described devices that no longer exist; there is nothing
        // to restore them from and nothing lost by their absence.
    }
}
