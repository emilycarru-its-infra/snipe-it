<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
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

    /**
     * The order categories are shown in, everywhere a catalog is browsed:
     * the store's pills, the store's shelves, and the catalog page behind
     * them. Computers first because that is what people come for; the
     * things that hang off a computer after. A category not listed here
     * sorts last, alphabetically, rather than disappearing.
     */
    public const CATEGORY_ORDER = ['Laptops', 'Desktops', 'Tablets', 'Displays', 'Accessories', 'Components', 'Scanners'];

    /** Where a category sits in {@see CATEGORY_ORDER}; unlisted ones go last. */
    public static function categoryRank(?string $category): int
    {
        $rank = array_search($category, self::CATEGORY_ORDER, true);

        return $rank === false ? count(self::CATEGORY_ORDER) : $rank;
    }

    protected $table = 'catalog_items';

    protected $fillable = [
        'part_numbers_verified_at',
        'supplier_id',
        'manufacturer_id',
        'model_id',
        'name',
        'description',
        'category',
        'subcategory',
        'family',
        'screen_size',
        'chip',
        'spec_cpu',
        'spec_gpu',
        'spec_npu',
        'ram_gb',
        'storage',
        'color',
        'display_finish',
        'extras',
        'warranty_months',
        'bundle_url',
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
        // When the part numbers were last checked against the vendor's current
        // list. CDW reissues EDCs even when the product has not changed, so
        // entered-once is not the same as still-right.
        'part_numbers_verified_at' => 'datetime',
        'unit_cost' => 'decimal:4',
        'estimated_cost' => 'decimal:4',
        'is_active' => 'boolean',
        'show_in_store' => 'boolean',
        'store_sort' => 'integer',
        'ram_gb' => 'integer',
        'warranty_months' => 'integer',
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
        'warranty_months' => 'nullable|integer|min:0|max:120',
        'bundle_url' => 'nullable|url|max:1024',
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

    /**
     * The curated slice the storefront shows. Every device must resolve
     * to a real asset model — that is where its photo and its place in
     * the fleet come from. Accessories are exempt: cables and pencils
     * are not asset-tracked, so they carry their own uploaded image
     * instead.
     */
    public function scopeInStore($query)
    {
        return $query->where('is_active', true)
            ->where('show_in_store', true)
            ->where(fn ($q) => $q->whereNotNull('model_id')->orWhere('category', 'Accessories'));
    }

    /**
     * The spec rows the store lists under a configuration, in display
     * order. Only what the row actually knows shows up.
     *
     * @return array<string, string>
     */
    /**
     * Which side of the estate a machine belongs to. Read off the asset
     * model's manufacturer rather than the product name, because that is
     * the fact Snipe already holds — and the two never disagree, since a
     * row without a model is not a machine anyone can order.
     */
    public function platform(): ?string
    {
        $manufacturer = $this->model?->manufacturer?->name;

        if ($manufacturer === null) {
            return null;
        }

        return $manufacturer === 'Apple' ? 'Macintosh' : 'Windows';
    }

    public function specList(): array
    {
        $specs = [];

        if ($this->chip) {
            $specs['Chip'] = $this->chip;
        }
        if ($this->spec_cpu) {
            $specs['CPU'] = $this->spec_cpu;
        }
        if ($this->spec_gpu) {
            $specs['GPU'] = $this->spec_gpu;
        }
        if ($this->spec_npu) {
            $specs['Neural Engine'] = $this->spec_npu;
        }
        if ($this->ram_gb) {
            // "Unified memory" is Apple's architecture and Apple's phrase;
            // a ThinkStation's 64GB is just 64GB.
            $specs['Memory'] = $this->ram_gb.'GB'.($this->chip ? ' unified memory' : '');
        }
        if ($this->storage) {
            $specs['Storage'] = $this->storage.' SSD';
        }
        if ($this->screen_size) {
            $display = $this->screen_size.'-inch';
            if ($this->display_finish === 'nano') {
                $display .= ' Nano-texture';
            }
            $specs['Display'] = $display;
        } elseif ($this->display_finish === 'nano') {
            $specs['Display'] = 'Nano-texture glass';
        }
        if ($this->extras) {
            $specs['Also'] = $this->extras;
        }
        if ($this->warrantyLabel()) {
            $specs['Warranty'] = trans('admin/store/general.warranty_included', [
                'term' => $this->warrantyLabel(),
            ]);
        }

        return $specs;
    }

    /**
     * Clean a part number that arrived from a spreadsheet.
     *
     * PHP's own trim() only strips ASCII whitespace, and the whitespace that
     * actually turns up in a reseller's workbook is not ASCII: four EDC values
     * reached the live catalog carrying a trailing U+00A0 no-break space,
     * pasted from CDW's Excel export. Invisible in every UI, and it ships
     * straight into the part list their desk keys the order from, where a
     * lookup on "9094668\u{A0}" finds nothing.
     *
     * Also collapses the zero-width and narrow spaces Excel emits, for the
     * same reason: a part number is an identifier, so any character that
     * cannot be seen has no business being significant in it.
     */
    public static function tidyIdentifier(string $value): string
    {
        return trim(preg_replace('/[\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]/u', '', $value) ?? $value);
    }

    /**
     * The warranty term in the unit a reader thinks in. Stored in months
     * because the Lenovo and Microsoft terms are quoted that way, but a
     * whole number of years reads as years — "3 years", not "36 months".
     */
    public function warrantyLabel(): ?string
    {
        $months = (int) $this->warranty_months;

        if ($months <= 0) {
            return null;
        }

        return $months % 12 === 0
            ? trans_choice('admin/store/general.warranty_years', intdiv($months, 12), ['count' => intdiv($months, 12)])
            : trans_choice('admin/store/general.warranty_months', $months, ['count' => $months]);
    }

    /**
     * The image the store card shows: this row's own upload when it has
     * one, otherwise the linked asset model's image, so most rows need no
     * upload at all.
     */
    public function storeImageUrl(): ?string
    {
        if ($this->image) {
            return Storage::disk('public')->url('catalog/'.$this->image);
        }

        if ($this->model && $this->model->image) {
            return Storage::disk('public')->url(app('models_upload_path').$this->model->image);
        }

        return null;
    }
}
