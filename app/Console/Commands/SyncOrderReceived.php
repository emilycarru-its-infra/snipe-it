<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\OrderItem;
use App\Models\Statuslabel;
use Illuminate\Console\Command;

/**
 * Stamps order line items as received when their linked asset is already in
 * a deployable (Active) status. Going forward this happens automatically via
 * the asset observer; this command backfills existing data and is safe to
 * re-run. Each saved item rolls its parent order status forward.
 */
class SyncOrderReceived extends Command
{
    protected $signature = 'orders:sync-received {--dry-run : Report what would change without writing anything}';

    protected $description = 'Mark order line items received when their linked asset is in a deployable status.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $deployableStatusIds = Statuslabel::where('deployable', 1)->pluck('id');
        $assetIds = Asset::whereIn('status_id', $deployableStatusIds)->pluck('id');

        $items = OrderItem::where('item_type', Asset::class)
            ->whereIn('item_id', $assetIds)
            ->whereNull('received_at')
            ->get();

        if ($items->isEmpty()) {
            $this->info('No line items to update — receiving is already in sync.');

            return self::SUCCESS;
        }

        $orderCount = $items->pluck('order_id')->unique()->count();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Marking {$items->count()} line item(s) received across {$orderCount} order(s).");

        if ($dryRun) {
            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $item->received_at = now();
            $item->save();
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
