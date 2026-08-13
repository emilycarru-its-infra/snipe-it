<?php

namespace App\Console\Commands;

use App\Models\StoreOrder;
use App\Services\StoreOrderAssetProvisioner;
use Illuminate\Console\Command;

/**
 * Create the asset records a store order should already have.
 *
 * Provisioning happens once, when the order is placed, and it is
 * deliberately forgiving: a failure there logs and lets the order stand,
 * because losing somebody's order to a validation problem would be worse
 * than the missing records. The cost of that choice is that a failed
 * provision leaves no way back — order 10's two iPads existed nowhere and
 * the only remedy was building asset rows by hand, which is how a repair
 * introduces its own wrong data.
 *
 * This re-runs the real provisioner over what is missing, so a repair
 * produces exactly what placing the order should have. Idempotent by
 * count: it creates the shortfall and nothing more, so running it twice
 * is safe and running it on a healthy order does nothing.
 */
class ReprovisionStoreOrder extends Command
{
    protected $signature = 'store:reprovision
                            {order : Store order id, or its ECU-STORE-<id> reference}
                            {--dry-run : Report what is missing without creating anything}';

    protected $description = 'Create the asset records a store order is missing';

    public function handle(StoreOrderAssetProvisioner $provisioner): int
    {
        $order = $this->resolveOrder((string) $this->argument('order'));

        if (! $order) {
            $this->error('No such store order.');

            return self::FAILURE;
        }

        $order->load('items.catalogItem.model.fieldset.fields', 'user');

        $expected = $order->expectedAssetCount();
        $missing = $order->provisioningShortfall();

        $this->line("{$order->reference()} — {$order->user?->present()->fullName}");
        $this->line("  expected {$expected} asset(s), missing {$missing}");

        if ($missing === 0) {
            $this->info('  Nothing to do.');

            return self::SUCCESS;
        }

        // The provisioner builds every unit on the order, so re-running it
        // over a partly-provisioned one would duplicate whatever survived.
        // A wholly-failed order is the case this exists for; a partial one
        // wants a human deciding which units are real.
        if ($missing < $expected) {
            $this->error('  Partly provisioned — re-running would duplicate the '
                .($expected - $missing).' record(s) that already exist. Create the '
                ."remaining {$missing} by hand, or release the order and re-place it.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->comment('  Dry run — nothing created.');

            return self::SUCCESS;
        }

        $created = $provisioner->provision($order);

        $this->info("  Created {$created->count()} asset(s).");

        foreach ($created as $asset) {
            $this->line("    {$asset->asset_tag}  {$asset->model?->name}");
        }

        $remaining = $order->fresh()->load('items.catalogItem')->provisioningShortfall();

        if ($remaining > 0) {
            $this->warn("  Still missing {$remaining} — check the log for validation errors.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveOrder(string $argument): ?StoreOrder
    {
        if (preg_match('/^ECU-STORE-(\d+)$/i', trim($argument), $matches)) {
            return StoreOrder::find((int) $matches[1]);
        }

        return is_numeric($argument) ? StoreOrder::find((int) $argument) : null;
    }
}
