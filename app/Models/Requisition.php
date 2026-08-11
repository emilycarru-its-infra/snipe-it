<?php

namespace App\Models;

use App\Models\Traits\PlacesVendorOrders;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * A purchase order before it is a purchase order.
 *
 * The flow this models: build a basket of catalog lines here, print it,
 * key it into Colleague by hand, and come back with a REQM requisition
 * number. That REQM is the identifier the requisition trades under until
 * finance issues a P00… purchase order number, at which point
 * purchase_order_id links this record to the budget ledger.
 *
 * A requisition is deliberately not a draft `purchase_orders` row: that
 * table is what every procurement report sums for committed and remaining
 * budget, and an unapproved basket must not move those numbers.
 *
 * @property-read string $display_name
 * @property string|null $funding_account
 * @property string|null $lease_schedule
 * @property string|null $quote_number
 * @property string|null $quote_total
 * @property \Illuminate\Support\Carbon|null $quote_expires_at
 * @property \Illuminate\Support\Carbon|null $quote_confirmed_at
 * @property \Illuminate\Support\Carbon|null $vendor_sent_at
 * @property \Illuminate\Support\Carbon|null $vendor_changes_at
 * @property string|null $vendor_changes_notes
 * @property string|null $vendor_order_number
 * @property string|null $order_cc
 */
class Requisition extends SnipeModel
{
    use HasFactory;
    use PlacesVendorOrders;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'requisitions';

    /**
     * Lifecycle, chronological:
     *   draft         — being built in the PO builder
     *   submitted     — handed off, awaiting entry in Colleague
     *   requisitioned — Colleague has issued the REQM number
     *   ordered       — finance issued the PO number; purchase_order_id set
     *   cancelled     — abandoned
     */
    public const STATUSES = [
        'draft',
        'submitted',
        'requisitioned',
        'ordered',
        'cancelled',
    ];

    /**
     * How long a catalog row's part numbers are trusted before the order form
     * flags them. A quarter, because that is the cadence CDW can supply an
     * updated list from the distribution warehouses at.
     */
    public const PART_NUMBER_STALE_DAYS = 92;

    protected $rules = [
        'title' => 'required|string|max:191',
        'status' => 'required|string|in:draft,submitted,requisitioned,ordered,cancelled',
        'requisition_number' => 'nullable|string|max:191',
        'supplier_id' => 'nullable|exists:suppliers,id',
        'company_id' => 'nullable|exists:companies,id',
        'purchase_order_id' => 'nullable|exists:purchase_orders,id',
        'fiscal_year' => 'nullable|string|max:16',
        'cost_center' => 'nullable|string|max:191',
        'default_gl_number' => 'nullable|string|max:191',
        'internal_comments' => 'nullable|string|max:65535',
        'printer_comments' => 'nullable|string|max:65535',
        'needed_by' => 'nullable|date',
        'gst_rate' => 'nullable|numeric|min:0|max:1',
        'pst_rate' => 'nullable|numeric|min:0|max:1',
        'shipping' => 'nullable|numeric|min:0',
        'notes' => 'nullable|string|max:65535',
        'quote_number' => 'nullable|string|max:191',
        'quote_total' => 'nullable|numeric|min:0',
        'quote_expires_at' => 'nullable|date',
        'funding_account' => 'nullable|string|in:purchase_admin,purchase_curriculum,lease_admin,lease_curriculum',
        'lease_schedule' => 'nullable|string|max:191',
        'vendor_order_number' => 'nullable|string|max:191',
    ];

    protected $injectUniqueIdentifier = true;

    protected $fillable = [
        'requisition_number',
        'title',
        'status',
        'supplier_id',
        'company_id',
        'purchase_order_id',
        'fiscal_year',
        'cost_center',
        'funding_account',
        'lease_schedule',
        'default_gl_number',
        'needed_by',
        'gst_rate',
        'pst_rate',
        'shipping',
        'submitted_at',
        'requisitioned_at',
        'vendor_sent_at',
        'vendor_changes_at',
        'vendor_changes_notes',
        'quote_number',
        'quote_total',
        'quote_expires_at',
        'quote_confirmed_at',
        'vendor_order_number',
        'order_cc',
        'notes',
        'internal_comments',
        'printer_comments',
        'created_by',
    ];

    protected $casts = [
        'needed_by' => 'date',
        'submitted_at' => 'datetime',
        'requisitioned_at' => 'datetime',
        'vendor_sent_at' => 'datetime',
        'vendor_changes_at' => 'datetime',
        'quote_confirmed_at' => 'datetime',
        'quote_expires_at' => 'date',
        'quote_total' => 'decimal:2',
        'gst_rate' => 'decimal:5',
        'pst_rate' => 'decimal:5',
        'shipping' => 'decimal:4',
    ];

    /**
     * @return HasMany<RequisitionItem, $this>
     */
    public function items()
    {
        return $this->hasMany(RequisitionItem::class, 'requisition_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * The purchase order this requisition became, once finance issued the
     * number. Null for everything that hasn't got there yet.
     *
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** Line items before tax and shipping. */
    public function subtotal(): float
    {
        return round($this->items->sum(fn (RequisitionItem $item) => $item->lineTotal()), 2);
    }

    /**
     * GST applies to everything including shipping; PST only to the lines
     * flagged for it (BC exempts software and some services), and we apply
     * it to shipping only when the requisition carries any taxable line.
     */
    public function pstBase(): float
    {
        return round($this->items
            ->filter(fn (RequisitionItem $item) => $item->pst_applicable)
            ->sum(fn (RequisitionItem $item) => $item->lineTotal()), 2);
    }

    public function gstAmount(): float
    {
        return round(($this->subtotal() + (float) $this->shipping) * (float) $this->gst_rate, 2);
    }

    public function pstAmount(): float
    {
        return round($this->pstBase() * (float) $this->pst_rate, 2);
    }

    public function total(): float
    {
        return round($this->subtotal() + (float) $this->shipping + $this->gstAmount() + $this->pstAmount(), 2);
    }

    /**
     * Whether any line is still priced off an estimate rather than a quote.
     * Surfaced on the requisition so nobody keys a ballpark into Colleague
     * without knowing it is one.
     */
    public function hasEstimatedLines(): bool
    {
        return $this->items->contains(fn (RequisitionItem $item) => $item->isEstimate());
    }











    /**
     * The lines this requisition would send: its own. The purchase order it
     * resolves to gathers these together with any sibling requisition's.
     *
     * @return \Illuminate\Support\Collection<int, RequisitionItem>
     */
    public function vendorOrderLines()
    {
        return $this->items;
    }

    /**
     * @return HasMany<StoreOrder, $this>
     */
    public function storeOrders()
    {
        return $this->hasMany(StoreOrder::class, 'requisition_id');
    }

    /** @return array<int, string> */
    public function storeOrderRequesterEmails(): array
    {
        return $this->storeOrders()->with('user')->get()
            ->map(fn (StoreOrder $order) => $order->user?->email)
            ->filter()->values()->all();
    }

    /**
     * Whether the basket can still be rewritten.
     *
     * Two hard edges with a deliberate gap between them. Once a requisition is
     * keyed into Colleague its lines are what was keyed, so it locks — but a
     * vendor quote is exactly the event that makes our figures wrong, and when
     * one arrives we reprice against it, because the quote is what the invoice
     * will match. Recording the quote number is what opens the basket back up,
     * and sending the order closes it for good: after that, changing a line
     * would mean the vendor and we are holding different orders.
     */
    public function linesEditable(): bool
    {
        // Accepting the final quote is the point of no return, not the send:
        // after that the vendor is placing what we agreed, and changing a line
        // would leave the two sides holding different orders.
        if ($this->quote_confirmed_at !== null) {
            return false;
        }

        // Still in the builder, or the vendor has said something that makes our
        // figures wrong — a quote, or a substitution for a part they can no
        // longer get. Both are reasons to reprice; the second arrives *after*
        // the order has gone out, which is why sending alone does not lock it.
        return $this->status === 'draft'
            || filled($this->quote_number)
            || $this->vendor_changes_at !== null;
    }



    /**
     * The number this requisition trades under: the PO number once finance
     * has issued one, the REQM until then, and the internal id before that.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->purchaseOrder?->po_number
                ?: ($this->requisition_number ?: ('REQ-'.$this->id)),
        );
    }

    /** Baskets still being built. */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /** Anything that has left the builder and not been abandoned. */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['draft', 'submitted', 'requisitioned']);
    }
}
