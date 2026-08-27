<?php

namespace App\Services;

use App\Models\AssetModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;

/**
 * Give a purchase order the order and the devices it should have had.
 *
 * Promotion raises both now, but purchase orders promoted before that
 * exist with nothing underneath: no order row, so the purchase order reads
 * as nothing committed; no assets, so the shipment webhook has nothing to
 * claim and a deployment wave built for them comes up empty.
 *
 * Idempotent by construction. An order already under the purchase order is
 * reused rather than duplicated, and provisioning counts what is already
 * standing so a second run tops up a shortfall instead of doubling a fleet.
 */
class PurchaseOrderProvisioning
{
    public function __construct(private OrderAssetProvisioner $provisioner) {}

    /**
     * @return array<string, mixed>
     */
    public function backfill(PurchaseOrder $purchaseOrder, bool $dryRun = false): array
    {
        $requisition = Requisition::with('items.catalogItem')
            ->where('purchase_order_id', $purchaseOrder->id)
            ->first();

        if (! $requisition) {
            return [
                'ok' => false,
                'error' => trans('admin/orders/general.backfill_no_requisition', ['po' => $purchaseOrder->po_number]),
            ];
        }

        $order = Order::where('purchase_order_id', $purchaseOrder->id)->first();

        $deviceUnits = $requisition->items
            ->filter(fn ($line) => $line->catalogItem?->model_id)
            ->sum(fn ($line) => (int) $line->quantity);

        $existing = $order ? $this->provisioner->existingFor($order) : 0;

        $report = [
            'ok' => true,
            'purchase_order' => $purchaseOrder->po_number,
            'requisition' => $requisition->requisition_number ?: ('REQ-'.$requisition->id),
            'order_existed' => (bool) $order,
            'device_units_on_requisition' => $deviceUnits,
            'assets_already_standing' => $existing,
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            $report['would_create'] = max(0, $deviceUnits - $existing);

            return $report;
        }

        if (! $order) {
            $order = $this->raiseOrder($requisition, $purchaseOrder);

            if (! $order) {
                return ['ok' => false, 'error' => trans('admin/orders/general.backfill_order_failed')];
            }
        }

        $created = $this->provisioner->provision($order->load('items', 'purchaseOrder'));

        $report['order'] = $order->order_number;
        $report['assets_created'] = $created->count();
        $report['assets_now'] = $this->provisioner->existingFor($order);

        return $report;
    }

    private function raiseOrder(Requisition $requisition, PurchaseOrder $purchaseOrder): ?Order
    {
        $order = new Order;
        // Ours until they issue theirs: the n-th order against this purchase
        // order. Never the bare purchase order number — that is the budget's
        // name, and the shipment webhook matches on the vendor's.
        $order->order_number = $purchaseOrder->po_number.'-'.($purchaseOrder->orders()->count() + 1);
        $order->purchase_order_id = $purchaseOrder->id;
        $order->supplier_id = $requisition->supplier_id;
        $order->company_id = $requisition->company_id;
        $order->fiscal_year = $purchaseOrder->fiscal_year;
        $order->order_date = $purchaseOrder->order_date;
        $order->status = 'ordered';
        $order->is_planned = false;
        $order->notes = $requisition->notes;

        if (! $order->save()) {
            return null;
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

        return $order;
    }
}
