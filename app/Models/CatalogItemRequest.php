<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to add a catalog row from a vendor link.
 *
 * Kept even when it creates nothing. A link that failed, or that resolved to
 * something already in the catalog, is the more useful record: it says what
 * people are looking for and cannot find, which is exactly what the curated
 * catalog needs to hear.
 *
 * @property string $outcome created|duplicate|failed
 */
class CatalogItemRequest extends Model
{
    protected $table = 'catalog_item_requests';

    public const CREATED = 'created';
    public const DUPLICATE = 'duplicate';
    public const FAILED = 'failed';

    protected $fillable = [
        'created_by',
        'url',
        'vendor_sku',
        'name',
        'catalog_item_id',
        'outcome',
        'error',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem()
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }
}
