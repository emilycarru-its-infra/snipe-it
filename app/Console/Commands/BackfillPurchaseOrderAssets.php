<?php

namespace App\Console\Commands;

use App\Models\AssetModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Services\OrderAssetProvisioner;
use Illuminate\Console\Command;

/**
 * Give a purchase order raised before promotion provisioned anything the
 * order and the assets it should have had.
 *
 * Promotion now raises an order and provisions from it, but purchase
 * orders promoted before that exist with nothing underneath: no order
 * row, no devices, so a deployment wave built for them is empty and the
 * shipment webhook has nothing to claim.
 *
 * Idempotent. An order already under the purchase order is left alone,
 * and provisioning tops up the shortfall rather than doubling the fleet.
 */
class BackfillPurchaseOrderAssets extends Command
{
    protected $signature = 'procurement:backfill-po-assets
                            {po : The purchase order number, e.g. P0026022}
                            {--dry-run : Report what would be created and change nothing}';

    protected $description = 'Raise the missing order and provision its assets for an existing purchase order';

    public function handle(OrderAssetProvisioner $provisioner): int
    {
        $poNumber = $this->argument('po');
        $dryRun = (bool) $this->option('dry-run');

        $purchaseOrder = PurchaseOrder::where('po_number', $poNumber)->first();

        if (! $purchaseOrder) {
            $this->error("No purchase order {$poNumber}.");

            return self::FAILURE;
        }

        $requisition = Requisition::with('items.catalogItem')
            ->where('purchase_order_id', $purchaseOrder->id)
            ->first();

        if (! $requisition) {
            $this->error("{$poNumber} has no requisition behind it — nothing to read lines from.");

            return self::FAILURE;
        }

        $order = Order::where('purchase_order_id', $purchaseOrder->id)->first();

        if ($order) {
            $this->line("Order {$order->order_number} already exists for {$poNumber}.");
        } else {
            $this->line("Would raise order {$poNumber} with {$requisition->items->count()} lines.");
        }

        $devices = $requisition->items
            ->filter(fn ($line) => $line->catalogItem?->model_id)
            ->sum(fn ($line) => (int) $line->quantity);

        $this->line("Device units on the requisition: {$devices}");

        if ($order) {
            $this->line('Assets already carrying that order number: '.$provisioner->existingFor($order));
        }

        if ($dryRun) {
            $this->info('Dry run — nothing written.');

            return self::SUCCESS;
        }

        if (! $order) {
            $order = new Order;
            $order->order_number = $purchaseOrder->po_number;
            $order->purchase_order_id = $purchaseOrder->id;
            $order->supplier_id = $requisition->supplier_id;
            $order->company_id = $requisition->company_id;
            $order->fiscal_year = $purchaseOrder->fiscal_year;
            $order->order_date = $purchaseOrder->order_date;
            $order->status = 'ordered';
            $order->is_planned = false;
            $order->notes = $requisition->notes;

            if (! $order->save()) {
                $this->error('Could not raise the order: '.$order->getErrors()->first());

                return self::FAILURE;
            }

            foreach ($requisition->items as $line) {
                $modelId = $line->catalogItem?->model_id;

                OrderItem::create([
                    'order_id' => $order->id,
                    'purchase_order_id' => $purchaseOrder->id,
                    'item_type' => $modelId ? AssetModel::class : null,
                    'item_id' => $modelId,
                    'replaces_asset_id' => $line->replaces_asset_id,
                    'description' => $line->description,
                    'quantity' => (int) $line->quantity,
                    'unit_cost' => (float) $line->unit_cost,
                ]);
            }

            $this->info("Raised order {$order->order_number}.");
        }

        $created = $provisioner->provision($order->load('items', 'purchaseOrder'));

        $this->info("Provisioned {$created->count()} assets against {$poNumber}.");

        return self::SUCCESS;
    }
}
