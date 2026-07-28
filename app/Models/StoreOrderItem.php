<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a store order. Description, SKUs and price are snapshotted
 * from the catalog at submit time — the same rule as requisition lines: a
 * price-list refresh must never re-total an order already in the queue.
 */
class StoreOrderItem extends Model
{
    use HasFactory;

    protected $table = 'store_order_items';

    protected $fillable = [
        'store_order_id',
        'catalog_item_id',
        'description',
        'vendor_sku',
        'mfr_part_number',
        'quantity',
        'unit_cost',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:4',
    ];

    /**
     * @return BelongsTo<StoreOrder, $this>
     */
    public function order()
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    /**
     * @return BelongsTo<CatalogItem, $this>
     */
    public function catalogItem()
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function lineTotal(): float
    {
        return round((float) $this->unit_cost * (int) $this->quantity, 2);
    }
}
