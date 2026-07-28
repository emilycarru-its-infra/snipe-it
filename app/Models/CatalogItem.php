<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * One purchasable SKU on a supplier's price list — the menu the PO builder
 * shops from. Rows arrive by import from the reseller's periodic workbook
 * (CDW's Apple EDC list, the Lenovo equivalent) and are keyed on the
 * reseller's own part number so a refreshed list updates prices in place
 * rather than duplicating the catalog.
 *
 * Price quality is explicit. `unit_cost` is a number the reseller actually
 * quoted; `estimated_cost` is the "~C$3300" ballpark the standard catalog
 * carries. `price_type` says which of the two a row is trading on, so a
 * requisition can show a reader that a line is still an estimate.
 */
class CatalogItem extends Model
{
    use HasFactory;
    use SoftDeletes;
    use ValidatingTrait;

    /** A stocked configuration vs. a configure-to-order build. */
    public const PRODUCT_TYPES = ['standard', 'cto'];

    public const PRICE_TYPES = ['quoted', 'estimate', 'list'];

    protected $table = 'catalog_items';

    protected $fillable = [
        'supplier_id',
        'manufacturer_id',
        'model_id',
        'name',
        'description',
        'category',
        'subcategory',
        'product_type',
        'vendor_sku',
        'mfr_part_number',
        'unit_cost',
        'estimated_cost',
        'currency',
        'price_type',
        'source',
        'quoted_at',
        'expires_at',
        'is_active',
        'show_in_store',
        'image',
        'store_sort',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:4',
        'estimated_cost' => 'decimal:4',
        'is_active' => 'boolean',
        'show_in_store' => 'boolean',
        'store_sort' => 'integer',
        'quoted_at' => 'date',
        'expires_at' => 'date',
    ];

    protected $rules = [
        'name' => 'required|string|max:191',
        'category' => 'nullable|string|max:191',
        'subcategory' => 'nullable|string|max:191',
        'product_type' => 'required|string|in:standard,cto',
        'price_type' => 'required|string|in:quoted,estimate,list',
        'vendor_sku' => 'nullable|string|max:191',
        'mfr_part_number' => 'nullable|string|max:191',
        'unit_cost' => 'nullable|numeric|min:0',
        'estimated_cost' => 'nullable|numeric|min:0',
        'currency' => 'nullable|string|max:8',
        'supplier_id' => 'nullable|integer|exists:suppliers,id',
        'manufacturer_id' => 'nullable|integer|exists:manufacturers,id',
        'model_id' => 'nullable|integer|exists:models,id',
        'source' => 'nullable|string|max:191',
        'notes' => 'nullable|string|max:65535',
    ];

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<Manufacturer, $this>
     */
    public function manufacturer()
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    /**
     * The asset model this SKU resolves to, when the catalog has been
     * matched up to the fleet's own model list.
     *
     * @return BelongsTo<AssetModel, $this>
     */
    public function model()
    {
        return $this->belongsTo(AssetModel::class, 'model_id');
    }

    /**
     * The price to build a requisition line on: the quoted number when we
     * have one, otherwise the estimate. Never null, so a line always totals.
     */
    public function effectiveCost(): float
    {
        return (float) ($this->unit_cost ?? $this->estimated_cost ?? 0);
    }

    /**
     * Whether effectiveCost() is a real quote or a ballpark — drives the
     * "estimate" badge in the builder so nobody keys a guess into Colleague
     * believing it was quoted.
     */
    public function isEstimate(): bool
    {
        return $this->unit_cost === null || $this->price_type === 'estimate';
    }

    /**
     * A quote that has aged out. Rows with no expiry never expire.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Live rows only — the default scope for anything reader-facing. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The curated slice the storefront shows. */
    public function scopeInStore($query)
    {
        return $query->where('is_active', true)->where('show_in_store', true);
    }

    /**
     * The image the store card shows: this row's own upload when it has
     * one, otherwise the linked asset model's image, so most rows need no
     * upload at all.
     */
    public function storeImageUrl(): ?string
    {
        if ($this->image) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url('catalog/'.$this->image);
        }

        if ($this->model && $this->model->image) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url(app('models_upload_path').$this->model->image);
        }

        return null;
    }
}
