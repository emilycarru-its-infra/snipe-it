<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'purchase_order_id',
        'shipment_id',
        'invoice_id',
        'item_type',
        'item_id',
        'replaces_asset_id',
        'description',
        'quantity',
        'unit_cost',
        'warranty_cost',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A line item linked to an asset that is already in a deployable
        // (Active) status is received on arrival — the device is in service.
        static::creating(function (OrderItem $item) {
            if (is_null($item->received_at) && $item->item_type === Asset::class && $item->item_id) {
                $asset = Asset::find($item->item_id);
                if ($asset && $asset->status && $asset->status->deployable) {
                    $item->received_at = now();
                }
            }
        });

        // Adding, removing or receiving a line item can change where the
        // parent order sits in its lifecycle, so re-derive its status.
        static::saved(fn (OrderItem $item) => $item->order?->recalculateStatus());
        static::deleted(fn (OrderItem $item) => $item->order?->recalculateStatus());
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * The purchase order this line item is charged to. Carried per line
     * item, not per order, so a single vendor order can be split across
     * purchase orders.
     *
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * The shipment this line item arrived (or will arrive) in, when assigned.
     *
     * @return BelongsTo<OrderShipment, $this>
     */
    public function shipment()
    {
        return $this->belongsTo(OrderShipment::class, 'shipment_id');
    }

    /**
     * The invoice this line item was billed on, when assigned.
     *
     * @return BelongsTo<OrderInvoice, $this>
     */
    public function invoice()
    {
        return $this->belongsTo(OrderInvoice::class, 'invoice_id');
    }

    /**
     * The full cost of this line: equipment plus any warranty/soft cost.
     */
    public function lineTotal(): float
    {
        return ((float) $this->unit_cost * (int) $this->quantity) + (float) $this->warranty_cost;
    }

    /**
     * The end-of-life asset this planned line item is forecast to replace,
     * when generated from the Refresh Forecast report.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function replacesAsset()
    {
        return $this->belongsTo(Asset::class, 'replaces_asset_id');
    }

    /**
     * The asset or consumable this line item resolves to, when linked.
     * Null item_type/item_id means a free-text line that isn't yet
     * matched to an inventory record.
     *
     * @return MorphTo<Model, $this>
     */
    public function item()
    {
        return $this->morphTo();
    }

    /**
     * Whether this line item has been received.
     */
    public function isReceived(): bool
    {
        return ! is_null($this->received_at);
    }

    /**
     * Mark this line received. Idempotent — a no-op if already received, so
     * receiving via the line button and via its shipment can't double-count.
     * When the line resolves to a consumable, the received quantity arrives
     * as stock: the consumable's qty is bumped and a 'checkin from' lands in
     * its history, tying the arrival to this order.
     */
    public function markReceived(): bool
    {
        if ($this->isReceived()) {
            return false;
        }

        $this->received_at = now();
        $this->save();

        $this->adjustLinkedConsumableStock((int) $this->quantity);

        return true;
    }

    /**
     * Undo receiving on this line. Reverses the stock bump for a linked
     * consumable (floored at what's already checked out so we never go
     * negative).
     */
    public function markUnreceived(): bool
    {
        if (! $this->isReceived()) {
            return false;
        }

        $this->received_at = null;
        $this->save();

        $this->adjustLinkedConsumableStock(-(int) $this->quantity);

        return true;
    }

    /**
     * Apply a stock delta to the consumable this line resolves to (if any).
     * On a receipt (positive delta) we log a first-class 'checkin from' tied
     * to the order and suppress the observer's generic update row; on an undo
     * (negative delta) we let the observer log the qty correction.
     */
    protected function adjustLinkedConsumableStock(int $delta): void
    {
        if ($delta === 0 || $this->item_type !== Consumable::class || ! $this->item_id) {
            return;
        }

        $consumable = Consumable::find($this->item_id);
        if (! $consumable) {
            return;
        }

        $checkedOut = (int) $consumable->numCheckedOut();
        $newQty = max($checkedOut, 0, (int) $consumable->qty + $delta);
        $applied = $newQty - (int) $consumable->qty;
        if ($applied === 0) {
            return;
        }

        $consumable->qty = $newQty;
        $consumable->skipChangeLog = $applied > 0;
        $consumable->save();

        if ($applied > 0) {
            $log = new Actionlog;
            $log->item_type = Consumable::class;
            $log->item_id = $consumable->id;
            $log->created_by = auth()->id();
            $log->quantity = $applied;
            $log->note = trans('admin/consumables/general.received_on_order', [
                'order' => $this->order?->order_number ?: ('#'.$this->order_id),
            ]);
            $log->logaction('checkin from');
        }
    }
}
