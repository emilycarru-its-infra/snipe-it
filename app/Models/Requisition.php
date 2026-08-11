<?php

namespace App\Models;

use App\Services\CdwAccounts;
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
 */
class Requisition extends SnipeModel
{
    use HasFactory;
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
        'funding_account' => 'nullable|string|in:lease,purchase,curriculum,grant',
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
     * Whether this can be sent to the vendor as an order.
     *
     * Three gates, each one a round trip through the vendor's desk if it is
     * missing:
     *
     *   the purchase order — the vendor places every line against a PO number
     *   the account        — which of the four CDW accounts, and so which
     *                        blanket PO and who gets invoiced; CDW cannot
     *                        infer it, and a lease account also needs its CSI
     *                        schedule so the invoice reaches the right
     *                        Exhibit A
     *   the part numbers   — both of them. The manufacturer's number is what
     *                        identifies the product and CDW's own number (the
     *                        EDC) is what they actually place; a line missing
     *                        either cannot be ordered
     *
     * Estimated prices are explicitly *not* a gate. Ours are estimates by
     * design and the returned quote is the authoritative number, so an order
     * priced off the last price list is a normal order, not a draft.
     */
    public function readyForVendor(): bool
    {
        return $this->purchase_order_id !== null
            && $this->status !== 'cancelled'
            && $this->items->isNotEmpty()
            && $this->fundingResolved()
            && $this->linesMissingPartNumbers()->isEmpty();
    }

    /**
     * An account, and — when that account is billed to CSI Leasing rather than
     * to ECU — the schedule the invoice lands on. Both lease accounts need one,
     * not just the one called "lease": a curriculum order is financed by CSI
     * too, and its invoice has to reach the right Exhibit A.
     */
    public function fundingResolved(): bool
    {
        if ($this->funding_account === null) {
            return false;
        }

        return ! CdwAccounts::needsSchedule($this->funding_account) || filled($this->lease_schedule);
    }

    /**
     * Store requests whose lines are on this requisition.
     *
     * The link matters for who hears about the order. A request that started in
     * the store has a person waiting at the end of it, and once their lines are
     * folded into a bulk requisition the thread that told them anything used to
     * stop. They are copied on the order that goes to the vendor and told again
     * when it ships.
     *
     * @return HasMany<StoreOrder, $this>
     */
    public function storeOrders()
    {
        return $this->hasMany(StoreOrder::class, 'requisition_id');
    }

    /**
     * Everyone who should be copied on the order: whoever asked for it through
     * the store, plus any address procurement typed into the send form.
     *
     * @return array<int, string>
     */
    public function orderCcAddresses(): array
    {
        $requesters = $this->storeOrders()->with('user')->get()
            ->map(fn (StoreOrder $order) => $order->user?->email)
            ->filter()
            ->all();

        return collect($requesters)
            ->merge(self::splitAddresses($this->order_cc))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Addresses as typed — comma, semicolon or newline separated, because
     * people paste from wherever they copied them.
     *
     * @return array<int, string>
     */
    public static function splitAddresses(?string $addresses): array
    {
        return collect(preg_split('/[,;\s]+/', (string) $addresses, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->all();
    }

    /** Just the word, for a column heading or a chip. */
    public function fundingLabel(): string
    {
        return $this->funding_account
            ? trans('admin/store/general.funding_'.$this->funding_account)
            : trans('admin/store/general.funding_unset');
    }

    /**
     * The account as the vendor's desk needs to read it — our word for it, the
     * account number, and what it is for — so they can match it to the right
     * blanket purchase order without asking.
     */
    public function fundingDescription(): string
    {
        return CdwAccounts::label($this->funding_account);
    }

    /**
     * Catalog lines the vendor could not place: missing a part number.
     *
     * Both numbers are required on a catalog line and they do different jobs —
     * the manufacturer's number says which product, CDW's own number (the EDC)
     * is the thing they place. Taeyoung Chang, asked directly which is the must
     * have: "Both EDC and MFR# would be ideal." So a shelf item short of either
     * is a bug in the catalog row, and the send refuses it.
     *
     * Free-form lines are deliberately exempt, because two legitimate kinds of
     * line have no part numbers to give:
     *
     *   charges     freight and the BC environmental handling fee appear on
     *               CDW's own quotes with a CDW number and no manufacturer's
     *               number, because nobody manufactures a delivery
     *   specials    a spec combination we have never bought has no EDC and no
     *               MFR# anywhere yet — CDW's desk prices it and issues them.
     *               Blocking those would push exactly the awkward orders back
     *               into email, which is the thing this replaces
     *
     * Returned rather than counted, because the fix is per line: a catalog row
     * is incomplete and somebody has to go and fill it in.
     *
     * @return \Illuminate\Support\Collection<int, RequisitionItem>
     */
    public function linesMissingPartNumbers()
    {
        return $this->items
            ->filter(fn (RequisitionItem $item) => $item->catalog_item_id !== null
                && (blank($item->vendor_sku) || blank($item->mfr_part_number)))
            ->values();
    }

    /**
     * Lines CDW has to price and number themselves: no part numbers at all.
     *
     * Called out in the order rather than left to be noticed, so their desk
     * knows which lines need an EDC issuing and which are simply charges. This
     * is the "special request" case Rod keeps open with them on purpose.
     *
     * @return \Illuminate\Support\Collection<int, RequisitionItem>
     */
    public function specialRequestLines()
    {
        return $this->items
            ->filter(fn (RequisitionItem $item) => $item->catalog_item_id === null
                && blank($item->vendor_sku) && blank($item->mfr_part_number))
            ->values();
    }

    /**
     * Catalog lines whose part numbers have not been checked against the
     * vendor's current list this quarter.
     *
     * CDW reissues EDCs and part numbers even when the product itself has not
     * changed — and always when a custom build is repriced — so a shelf row can
     * be quietly wrong without anybody touching it. Taeyoung's answer to that
     * is a quarterly list from the distribution warehouses, so a row unverified
     * for more than a quarter is the one to distrust. A warning, never a gate:
     * a stale number is CDW's desk asking a question, not a wrong order.
     *
     * @return \Illuminate\Support\Collection<int, RequisitionItem>
     */
    public function linesWithStalePartNumbers()
    {
        return $this->items
            ->filter(function (RequisitionItem $item) {
                $verified = $item->catalogItem?->part_numbers_verified_at;

                return $item->catalog_item_id !== null
                    && ($verified === null || $verified->lt(now()->subDays(self::PART_NUMBER_STALE_DAYS)));
            })
            ->values();
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
     * Where the order stands with the vendor, as one word.
     *
     * The vendor's loop is not a single "ordered" flag — their rep set it out as
     * seven steps, and the four this side records are the ones where somebody
     * has to decide something. Read newest-first, because the latest fact is
     * the state:
     *
     *   confirmed  we accepted the final quote; they are placing it
     *   changes    they came back with a substitution or a reissued part number
     *   sent       with them, awaiting an answer
     *   ready      everything needed to send, not sent
     *
     * An order number arriving is the end of the loop and outranks all of it.
     */
    public function vendorStage(): string
    {
        return match (true) {
            filled($this->vendor_order_number) => 'placed',
            $this->quote_confirmed_at !== null => 'confirmed',
            $this->vendor_changes_at !== null => 'changes',
            $this->vendor_sent_at !== null => 'sent',
            $this->readyForVendor() => 'ready',
            default => 'incomplete',
        };
    }

    /**
     * What the vendor's authoritative cost is, once they have quoted: their
     * number when we have it, our own arithmetic until then.
     */
    public function vendorTotal(): float
    {
        return $this->quote_total !== null ? (float) $this->quote_total : $this->total();
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
