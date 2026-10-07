<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price a lessor put on one device.
 *
 * Kept as its own row because buyout quotes supersede: a lessor priced a
 * device and re-priced the same device lower a few days later. Only the newest is live — it is mirrored onto the parent buyout —
 * but the earlier one has to stay readable, because in a mail thread the
 * stale figure is the one people keep quoting back.
 */
class AssetBuyoutQuote extends Model
{
    use HasFactory;

    protected $table = 'asset_buyout_quotes';

    protected $fillable = [
        'asset_buyout_id',
        'quote_amount',
        'remaining_rent',
        'quote_total',
        'quoted_at',
        'reference',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'quote_amount' => 'decimal:2',
        'remaining_rent' => 'decimal:2',
        'quote_total' => 'decimal:2',
        'quoted_at' => 'date',
    ];

    /** @return BelongsTo<AssetBuyout, $this> */
    public function buyout(): BelongsTo
    {
        return $this->belongsTo(AssetBuyout::class, 'asset_buyout_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
