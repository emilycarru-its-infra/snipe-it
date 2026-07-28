<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a requisition.
 *
 * The description, SKUs and unit cost are snapshotted from the catalog when
 * the line is added rather than joined at read time: a price-list refresh
 * must never silently re-total a requisition that is already sitting in
 * someone's approval queue or has been keyed into Colleague.
 * catalog_item_id is kept as provenance, not as the source of the numbers.
 */
class RequisitionItem extends Model
{
    use HasFactory;

    protected $table = 'requisition_items';

    protected $fillable = [
        'requisition_id',
        'catalog_item_id',
        'replaces_asset_id',
        'description',
        'vendor_sku',
        'mfr_part_number',
        'gl_number',
        'quantity',
        'unit_of_measure',
        'unit_cost',
        'pst_applicable',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:4',
        'pst_applicable' => 'boolean',
    ];

    /**
     * @return BelongsTo<Requisition, $this>
     */
    public function requisition()
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }

    /**
     * The catalog row this line was added from, when it came from the
     * catalog rather than being typed in free-hand.
     *
     * @return BelongsTo<CatalogItem, $this>
     */
    public function catalogItem()
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    /**
     * The end-of-life asset this line is buying a replacement for, when the
     * line was slotted in from the Refresh Forecast report.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function replacesAsset()
    {
        return $this->belongsTo(Asset::class, 'replaces_asset_id');
    }

    public function lineTotal(): float
    {
        return round((float) $this->unit_cost * (int) $this->quantity, 2);
    }

    /**
     * Whether this line's price came off an estimate rather than a quote.
     * Read from the originating catalog row — a hand-typed line with no
     * catalog provenance is treated as an estimate, because it is one.
     */
    public function isEstimate(): bool
    {
        return $this->catalogItem === null || $this->catalogItem->isEstimate();
    }
}
