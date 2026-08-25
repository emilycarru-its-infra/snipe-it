<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderProvisioning;
use Illuminate\Console\Command;

/**
 * Give a purchase order raised before promotion provisioned anything the
 * order and the assets it should have had.
 *
 * The work lives in PurchaseOrderProvisioning because the API does this
 * too — a container with no shell to run artisan in is exactly the case
 * that needs an endpoint, and the two doors must not drift.
 */
class BackfillPurchaseOrderAssets extends Command
{
    protected $signature = 'procurement:backfill-po-assets
                            {po : The purchase order number, e.g. P0026022}
                            {--dry-run : Report what would be created and change nothing}';

    protected $description = 'Raise the missing order and provision its assets for an existing purchase order';

    public function handle(PurchaseOrderProvisioning $provisioning): int
    {
        $purchaseOrder = PurchaseOrder::where('po_number', $this->argument('po'))->first();

        if (! $purchaseOrder) {
            $this->error('No purchase order '.$this->argument('po').'.');

            return self::FAILURE;
        }

        $report = $provisioning->backfill($purchaseOrder, (bool) $this->option('dry-run'));

        if (! ($report['ok'] ?? false)) {
            $this->error($report['error'] ?? 'Backfill failed.');

            return self::FAILURE;
        }

        foreach ($report as $key => $value) {
            $this->line(str_pad($key, 32).(is_bool($value) ? ($value ? 'yes' : 'no') : $value));
        }

        return self::SUCCESS;
    }
}
