<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One device leaving the fleet by purchase.
 *
 * Opened by the "Request Buyout" button and closed when the device is the
 * buyer's. The stretch in between is the part that used to live in a mail
 * thread: a lessor quote that may be re-priced, a split between what the
 * buyer pays and what ECU absorbs, the buyer's decision, the lessor's
 * invoice, and the payment — which is usually a payroll deduction, since
 * the lessor invoices ECU and the buyer reimburses.
 */
class AssetBuyout extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Lifecycle, chronological:
     *   requested  — quote request mailed to the lessor, nothing back yet
     *   quoted     — the lessor has priced it; the buyer has not decided
     *   approved   — the buyer said yes, the lessor has been told to action it
     *   invoiced   — the lessor's invoice is in hand, with a due date
     *   paid       — payment settled
     *   completed  — device released to the buyer, off the lease, done
     *   declined   — the buyer, or ECU, said no
     *
     * `declined` is terminal from anywhere; the rest run in order.
     */
    public const STATUSES = ['requested', 'quoted', 'approved', 'invoiced', 'paid', 'completed', 'declined'];

    /** Still moving — what the decommissioning lane counts as in flight. */
    public const OPEN_STATUSES = ['requested', 'quoted', 'approved', 'invoiced', 'paid'];

    /**
     * How the buyer settles up. The lessor invoices ECU either way; this is
     * how ECU is made whole. Payroll deduction is the usual route.
     */
    public const PAYMENT_METHODS = ['payroll_deduction', 'invoice', 'other'];

    protected $table = 'asset_buyouts';

    protected $fillable = [
        'asset_id',
        'lessor_id',
        'buyer_id',
        'requested_by',
        'status',
        'requested_at',
        'quote_amount',
        'remaining_rent',
        'quote_total',
        'quoted_at',
        'buyer_amount',
        'ecu_amount',
        'approved_at',
        'approved_by',
        'declined_at',
        'decline_reason',
        'invoice_number',
        'invoice_date',
        'invoice_due_date',
        'paid_at',
        'payment_method',
        'payment_reference',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'quote_amount' => 'decimal:2',
        'remaining_rent' => 'decimal:2',
        'quote_total' => 'decimal:2',
        'quoted_at' => 'date',
        'buyer_amount' => 'decimal:2',
        'ecu_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'declined_at' => 'datetime',
        'invoice_date' => 'date',
        'invoice_due_date' => 'date',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id')->withTrashed();
    }

    /** @return BelongsTo<Supplier, $this> */
    public function lessor(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'lessor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    /** @return HasMany<AssetBuyoutQuote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(AssetBuyoutQuote::class)->orderByDesc('quoted_at')->orderByDesc('id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Days since the request was mailed — how the lane sorts what is stalling. */
    public function age(): int
    {
        return (int) ($this->requested_at?->diffInDays(now()) ?? 0);
    }

    /**
     * The stage this record is waiting on, as a translation key. Distinct from
     * the status: a `requested` row is waiting on the lessor, a `quoted` one on
     * the buyer, and knowing which is the difference between chasing the right
     * party and chasing the wrong one.
     */
    public function waitingOn(): ?string
    {
        return match ($this->status) {
            'requested' => 'admin/deployments/general.buyout_waiting_lessor',
            'quoted' => 'admin/deployments/general.buyout_waiting_buyer',
            'approved' => 'admin/deployments/general.buyout_waiting_invoice',
            'invoiced' => 'admin/deployments/general.buyout_waiting_payment',
            'paid' => 'admin/deployments/general.buyout_waiting_release',
            default => null,
        };
    }

    /**
     * An invoice past its due date with nothing paid against it. The one
     * condition on this record that costs money to ignore.
     */
    public function isOverdue(): bool
    {
        return $this->status === 'invoiced'
            && $this->invoice_due_date !== null
            && $this->invoice_due_date->isPast();
    }
}
