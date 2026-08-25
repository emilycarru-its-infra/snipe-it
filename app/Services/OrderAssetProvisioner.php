<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Statuslabel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The devices an order is for, created when the order is placed.
 *
 * The store side has done this since the beginning: submit an order and the
 * machines exist immediately, tagged and waiting for their serials, so the
 * shipment webhook has something to claim and a deployment wave has
 * something to hold. The requisition side — REQM to purchase order to
 * order — did none of it, so forty-two laptops on a placed order existed
 * nowhere but as text on a requisition, and the wave built for them came up
 * empty.
 *
 * Same rule either way now: an order is what provisions. The asset carries
 * the order number so a shipment can find it, and the purchase order number
 * so the money reconciles.
 */
class OrderAssetProvisioner
{
    /** Status a pre-created asset waits in until its serial arrives. */
    public const ORDERED_STATUS = 'New (Ordered)';

    /**
     * Create one asset per unit on the order, skipping lines that resolve
     * to no model — warranty, freight and recycling fees are money on the
     * order, not devices to track.
     *
     * @return Collection<int, Asset>
     */
    public function provision(Order $order): Collection
    {
        $created = collect();
        $status = $this->orderedStatus();
        $poNumber = $order->purchaseOrder?->po_number;

        foreach ($order->items as $line) {
            $modelId = $this->modelFor($line);

            if (! $modelId) {
                continue;
            }

            for ($unit = 0; $unit < (int) $line->quantity; $unit++) {
                if ($asset = $this->provisionUnit($order, $line, $modelId, $status->id, $poNumber)) {
                    $created->push($asset);
                }
            }
        }

        return $created;
    }

    /**
     * Assets already standing for this order, so a second run tops up the
     * shortfall rather than doubling the fleet. Matched the way the
     * shipment webhook matches: by the order number on the asset.
     */
    public function existingFor(Order $order): int
    {
        return Asset::where('order_number', $order->order_number)->count();
    }

    /**
     * A line's model. Order lines point at what was bought polymorphically,
     * and only a line pointing at an asset model describes a device — which
     * is exactly the filter wanted here, since warranty, freight and
     * recycling lines point at nothing.
     */
    private function modelFor(OrderItem $line): ?int
    {
        return $line->item_type === AssetModel::class && $line->item_id
            ? (int) $line->item_id
            : null;
    }

    private function provisionUnit(Order $order, OrderItem $line, int $modelId, int $statusId, ?string $poNumber): ?Asset
    {
        $asset = new Asset;
        $asset->model_id = $modelId;
        $asset->status_id = $statusId;
        $asset->asset_tag = Asset::autoincrement_asset() ?: 'ORD-'.$order->id.'-'.uniqid();

        // Stock, not a person's machine: a requisition buys for a room or a
        // programme, and who gets which unit is decided at deployment.
        $asset->order_number = $order->order_number;
        $asset->po_number = $poNumber;
        $asset->purchase_cost = (float) $line->unit_cost ?: null;
        $asset->purchase_date = $order->order_date;
        $asset->supplier_id = $order->supplier_id;
        $asset->notes = trans('admin/orders/general.asset_provisioned_note', [
            'order' => $order->order_number,
        ]);

        if (! $asset->save()) {
            Log::error('Order '.$order->id.' could not provision an asset: '
                .json_encode($asset->getErrors()->all()));

            return null;
        }

        return $asset;
    }

    private function orderedStatus(): Statuslabel
    {
        return Statuslabel::firstOrCreate(
            ['name' => self::ORDERED_STATUS],
            ['notes' => 'Ordered from the supplier; serial arrives with the shipment.', 'pending' => 1,
                'archived' => 0, 'deployable' => 0, 'deleted_at' => null, 'default_label' => 0]
        );
    }
}
