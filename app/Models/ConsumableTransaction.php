<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A consumable transaction is one journal-transfer line.
 *
 * It records that a quantity of a GL-tracked consumable (a toner) was
 * checked out to a printer carrying a GL code. Rows move through a small
 * status pipeline: `draft` (captured at checkout) -> `posted` (rolled into a
 * generated GL Transfer form) -> `transferred` (Finance has confirmed the
 * journal entry).
 *
 * The GL code, unit cost and quantity are snapshots taken at checkout time,
 * so editing the printer or the consumable later never rewrites a recorded
 * transaction.
 */
class ConsumableTransaction extends Model
{
    use SoftDeletes;

    protected $table = 'consumable_transactions';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_TRANSFERRED = 'transferred';

    protected $fillable = [
        'consumable_id',
        'asset_id',
        'gl_code',
        'quantity',
        'unit_cost',
        'total_cost',
        'transaction_date',
        'fiscal_year',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'quantity' => 'integer',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
    ];

    public function consumable()
    {
        return $this->belongsTo(Consumable::class, 'consumable_id')->withTrashed();
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id')->withTrashed();
    }

    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeForFiscalYear($query, string $fiscalYear)
    {
        return $query->where('fiscal_year', $fiscalYear);
    }

    /**
     * ECU's fiscal year runs April -> March. April 2026 .. March 2027 is
     * "FY2026-27". Format matches the orders module (see BackfillOrders).
     */
    public static function fiscalYearFor($date): string
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $startYear = $date->month >= 4 ? $date->year : $date->year - 1;

        return 'FY'.$startYear.'-'.substr((string) ($startYear + 1), -2);
    }

    /**
     * Record a transaction for a consumable checked out to an asset.
     *
     * Returns null — recording nothing — when the asset has no GL code. A
     * printer without a GL code (a general/student printer) is intentionally
     * not chargeable, so its toner checkouts produce no journal-transfer line.
     */
    public static function recordCheckout(
        Consumable $consumable,
        Asset $asset,
        int $quantity,
        ?string $note,
        ?int $userId
    ): ?self {
        if (empty($asset->gl_code)) {
            return null;
        }

        $unitCost = is_numeric($consumable->purchase_cost) ? (float) $consumable->purchase_cost : null;
        $date = Carbon::now();

        return self::create([
            'consumable_id' => $consumable->id,
            'asset_id' => $asset->id,
            'gl_code' => $asset->gl_code,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost !== null ? $unitCost * $quantity : null,
            'transaction_date' => $date->toDateString(),
            'fiscal_year' => self::fiscalYearFor($date),
            'status' => self::STATUS_DRAFT,
            'notes' => $note,
            'created_by' => $userId,
        ]);
    }
}
