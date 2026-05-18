<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'shipment_id',
        'item_type',
        'item_id',
        'description',
        'quantity',
        'unit_cost',
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

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * The shipment this line item arrived (or will arrive) in, when assigned.
     */
    public function shipment()
    {
        return $this->belongsTo(OrderShipment::class, 'shipment_id');
    }

    /**
     * The asset or consumable this line item resolves to, when linked.
     * Null item_type/item_id means a free-text line that isn't yet
     * matched to an inventory record.
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
}
