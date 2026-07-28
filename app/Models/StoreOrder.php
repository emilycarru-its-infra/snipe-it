<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An end user's order from the internal store.
 *
 * "Order" in the storefront sense: a selection, not a purchase. Placing one
 * puts it in the procurement approval queue; approving it pulls its lines
 * into a requisition, and from there the existing chain takes over —
 * requisition → purchase order → vendor order, with the CDW webhook landing
 * serials and tracking on the vendor order's shipments. This record's own
 * status only covers the stretch that chain doesn't: from "placed" to
 * "on a requisition".
 */
class StoreOrder extends Model
{
    use HasFactory;

    /**
     * Lifecycle, chronological:
     *   pending   — placed, waiting for procurement review
     *   approved  — accepted but not yet pulled into a requisition
     *   ordered   — its lines are on a requisition (requisition_id set)
     *   declined  — rejected with a reason
     *   cancelled — withdrawn by the requester before review
     */
    public const STATUSES = ['pending', 'approved', 'ordered', 'declined', 'cancelled'];

    protected $table = 'store_orders';

    protected $fillable = [
        'user_id',
        'status',
        'notes',
        'decision_notes',
        'decided_by',
        'decided_at',
        'requisition_id',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    /**
     * @return HasMany<StoreOrderItem, $this>
     */
    public function items()
    {
        return $this->hasMany(StoreOrderItem::class, 'store_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    /**
     * The requisition this order's lines were pulled into on approval —
     * the bridge to the real procurement chain.
     *
     * @return BelongsTo<Requisition, $this>
     */
    public function requisition()
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }

    public function total(): float
    {
        return round($this->items->sum(fn (StoreOrderItem $item) => $item->lineTotal()), 2);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'approved'], true);
    }

    /**
     * What the requester sees on "my orders". Once the lines are on a
     * requisition, the truthful status comes from the procurement chain —
     * the store record stops being the authority the moment it hands off.
     */
    public function displayStatus(): string
    {
        if ($this->status === 'ordered' && $this->requisition) {
            return $this->requisition->purchaseOrder ? 'ordered' : 'processing';
        }

        return $this->status;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
