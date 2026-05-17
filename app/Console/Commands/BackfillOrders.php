<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;

/**
 * Builds the Order backlog from order numbers already on assets. Idempotent:
 * an Order is matched by order_number and a line item by (order, asset), so
 * re-running only fills gaps. Safe to validate with --limit before a full run.
 */
class BackfillOrders extends Command
{
    protected $signature = 'orders:backfill
        {--months=12 : Only include assets purchased within this many months}
        {--limit= : Only process this many orders (most recent first)}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Create Order records and line items from the order numbers on existing assets.';

    public function handle(): int
    {
        $months = (int) $this->option('months') ?: 12;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subMonths($months);

        $assets = Asset::whereNotNull('order_number')
            ->where('order_number', '!=', '')
            ->whereNotNull('purchase_date')
            ->where('purchase_date', '>=', $cutoff)
            ->get();

        $groups = $assets->groupBy('order_number')
            ->sortByDesc(fn ($groupAssets) => $groupAssets->max('purchase_date'));

        if ($limit !== null) {
            $groups = $groups->take($limit);
        }

        if ($groups->isEmpty()) {
            $this->info('No assets with order numbers found purchased since '.$cutoff->format('Y-m-d').'.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Processing '.$groups->count().' order(s) from assets purchased since '.$cutoff->format('Y-m-d').'.');

        $rows = [];
        $ordersCreated = 0;
        $itemsCreated = 0;

        foreach ($groups as $orderNumber => $groupAssets) {
            $orderCost = $groupAssets->sum(fn ($asset) => (float) $asset->purchase_cost);

            if ($dryRun) {
                $exists = Order::where('order_number', $orderNumber)->exists();
                $rows[] = [$orderNumber, $groupAssets->count(), number_format($orderCost, 2), $exists ? 'exists' : 'new'];

                continue;
            }

            $order = Order::firstOrCreate(
                ['order_number' => $orderNumber],
                [
                    'status' => 'received',
                    'supplier_id' => $groupAssets->pluck('supplier_id')->filter()->first(),
                    'company_id' => $groupAssets->pluck('company_id')->filter()->first(),
                    'order_date' => $groupAssets->min('purchase_date'),
                    'order_cost' => $orderCost,
                ]
            );

            if (! $order->exists) {
                $this->error("Skipped {$orderNumber}: ".implode('; ', $order->getErrors()->all()));

                continue;
            }

            if ($order->wasRecentlyCreated) {
                $ordersCreated++;
            }

            $newItems = 0;
            foreach ($groupAssets as $asset) {
                $item = OrderItem::firstOrCreate(
                    [
                        'order_id' => $order->id,
                        'item_type' => Asset::class,
                        'item_id' => $asset->id,
                    ],
                    [
                        'quantity' => 1,
                        'unit_cost' => $asset->purchase_cost,
                        'description' => $asset->name ?: $asset->asset_tag,
                    ]
                );

                if ($item->wasRecentlyCreated) {
                    $newItems++;
                    $itemsCreated++;
                }
            }

            $rows[] = [
                $orderNumber,
                $groupAssets->count(),
                number_format($orderCost, 2),
                $order->wasRecentlyCreated ? 'created' : "matched (+{$newItems} items)",
            ];
        }

        $this->table(['Order #', 'Assets', 'Total', $dryRun ? 'State' : 'Result'], $rows);

        if (! $dryRun) {
            $this->info("Done. Orders created: {$ordersCreated}. Line items created: {$itemsCreated}.");
        }

        return self::SUCCESS;
    }
}
