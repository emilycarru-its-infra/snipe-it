<?php

namespace App\Models;

use App\Models\Traits\HasUploads;
use App\Models\Traits\Loggable;
use App\Models\Traits\PlacesVendorOrders;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * A purchase order — the document an order is actually placed from.
 *
 * It is the budget unit: one purchase order, the number the finance ERP issued,
 * spans many vendor orders which each carry invoices and line items. Budget is
 * tracked here and spend rolls up from the orders.
 *
 * It is also the document that authorises buying, which is why the vendor-facing
 * side of an order lives here rather than on the requisition that produced it:
 * the account it is charged to, the vendor's quote, the send itself, and the
 * answers that come back. A requisition is transient — a basket to be keyed into
 * Colleague, carrying the REQM until this number exists — and after that it is a
 * tracking record. The vendor bills against this.
 *
 * @property-read \App\Models\Supplier|null $supplier
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
class PurchaseOrder extends SnipeModel
{
    use HasFactory;
    use HasUploads;
    use Loggable;
    use PlacesVendorOrders;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'purchase_orders';

    public const STATUSES = [
        'open',
        'amended',
        'closed',
        'cancelled',
    ];

    protected $rules = [
        'po_number' => 'required|string|max:191',
        'title' => 'nullable|string|max:191',
        'supplier_id' => 'nullable|exists:suppliers,id',
        'company_id' => 'nullable|exists:companies,id',
        'fiscal_year' => 'nullable|string|max:191',
        'budget' => 'nullable|numeric',
        'cost_center' => 'nullable|string|max:191',
        'status' => 'required|string|in:open,amended,closed,cancelled',
        'order_date' => 'nullable|date',
        'notes' => 'nullable|string|max:65535',
        'funding_account' => 'nullable|string|in:purchase_admin,purchase_curriculum,lease_admin,lease_curriculum',
        'lease_schedule' => 'nullable|string|max:191',
        'quote_number' => 'nullable|string|max:191',
        'quote_total' => 'nullable|numeric|min:0',
        'quote_expires_at' => 'nullable|date',
        'vendor_order_number' => 'nullable|string|max:191',
    ];

    protected $injectUniqueIdentifier = true;

    protected $fillable = [
        'po_number',
        'title',
        'supplier_id',
        'company_id',
        'fiscal_year',
        'budget',
        'cost_center',
        'status',
        'order_date',
        'notes',
        'funding_account',
        'lease_schedule',
        'quote_number',
        'quote_total',
        'quote_expires_at',
        'quote_confirmed_at',
        'vendor_sent_at',
        'vendor_changes_at',
        'vendor_changes_notes',
        'vendor_order_number',
        'order_cc',
    ];

    protected $casts = [
        'order_date' => 'date',
        'quote_expires_at' => 'date',
        'quote_total' => 'decimal:2',
        'quote_confirmed_at' => 'datetime',
        'vendor_sent_at' => 'datetime',
        'vendor_changes_at' => 'datetime',
    ];

    /**
     * How long a catalog row's part numbers are trusted before the order form
     * flags them. A quarter, because that is the cadence the vendor can supply
     * an updated list from the distribution warehouses at.
     */
    public const PART_NUMBER_STALE_DAYS = 92;

    protected $searchableAttributes = ['po_number', 'title', 'fiscal_year', 'cost_center', 'status', 'notes'];

    protected $searchableRelations = [
        'supplier' => ['name'],
        'company' => ['name'],
    ];

    /**
     * Addressed by its number, not by our row id.
     *
     * `/procurement/purchase-orders/P0026022` is the URL somebody can type from
     * a finance email or a PDF; `/purchase-orders/4` is a fact about our
     * database that nobody outside it knows. The number is unique, issued by the
     * finance ERP, and already the thing every other system quotes.
     *
     * Resolution still accepts an id — see the binding in routes/web.php — so
     * older links and any code passing an integer keep working.
     */
    public function getRouteKeyName(): string
    {
        return 'po_number';
    }

    /**
     * The requisitions that resolved to this purchase order — the baskets that
     * were keyed into Colleague to get this number. Normally one; more than one
     * when finance folded several REQMs into a single PO.
     */
    public function requisitions()
    {
        return $this->hasMany(Requisition::class, 'purchase_order_id');
    }

    /**
     * The lines to order, gathered from those requisitions. This is what the
     * vendor is sent: the purchase order is the authority, but the basket that
     * describes what to buy was built on the requisition.
     *
     * @return \Illuminate\Support\Collection<int, RequisitionItem>
     */
    public function vendorOrderLines()
    {
        // Through the loaded relation when there is one, so this works on a
        // model that was never saved — the email previewer in Settings → Emails
        // renders the order mail against sample objects with their relations set
        // by hand, and querying by a null id would return the whole table.
        $requisitions = $this->relationLoaded('requisitions')
            ? $this->getRelation('requisitions')
            : ($this->exists
                ? $this->requisitions()->with('items.catalogItem')->orderBy('id')->get()
                : collect());

        return $requisitions
            ->flatMap(fn (Requisition $requisition) => $requisition->items)
            ->values();
    }

    /**
     * Requesters to copy, taken from the store orders whose lines were folded
     * into this purchase order's requisitions.
     *
     * @return array<int, string>
     */
    public function storeOrderRequesterEmails(): array
    {
        if (! $this->exists) {
            return [];
        }

        return StoreOrder::query()
            ->whereIn('requisition_id', $this->requisitions()->pluck('id'))
            ->with('user')
            ->get()
            ->map(fn (StoreOrder $order) => $order->user?->email)
            ->filter()
            ->values()
            ->all();
    }

    /** Comments meant for the vendor, carried up from the requisition. */
    public function printerComments(): ?string
    {
        if ($this->relationLoaded('requisitions')) {
            return $this->getRelation('requisitions')->first()?->printer_comments;
        }

        return $this->exists ? $this->requisitions()->orderBy('id')->value('printer_comments') : null;
    }

    /** Comments that stay with us, carried up from the requisition. */
    public function internalComments(): ?string
    {
        if ($this->relationLoaded('requisitions')) {
            return $this->getRelation('requisitions')->first()?->internal_comments;
        }

        return $this->exists ? $this->requisitions()->orderBy('id')->value('internal_comments') : null;
    }

    /**
     * The vendor orders placed against this purchase order.
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'purchase_order_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * A purchase order has no `name` column; use the PO number for display.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->po_number,
        );
    }

    /**
     * The line items charged to this purchase order. PO membership is
     * carried per line item, so a single vendor order can be split across
     * purchase orders. Items on planned (forecast) orders are excluded —
     * only real commitments count.
     */
    public function lineItems()
    {
        return $this->hasMany(OrderItem::class, 'purchase_order_id')
            ->whereHas('order', fn ($query) => $query->where('is_planned', false));
    }

    /**
     * The vendor invoices charged to this purchase order. A vendor order
     * can be split across purchase orders, so each invoice carries its
     * own PO rather than inheriting the order's.
     */
    public function invoices()
    {
        return $this->hasMany(OrderInvoice::class, 'purchase_order_id');
    }

    /**
     * Total billed against this PO, pre-tax — the subtotal of every
     * invoice charged to it (budget and committed are also pre-tax).
     */
    public function invoicedTotal(): float
    {
        return (float) $this->invoices()->sum('subtotal');
    }

    /**
     * Committed spend: the cost of every line item charged to this PO,
     * invoiced or not. This is the figure compared against the budget.
     */
    public function committedTotal(): float
    {
        return (float) $this->lineItems()->get()->sum->lineTotal();
    }

    /**
     * Committed spend booked in a single fiscal year, attributed by the FY
     * of the order each line item sits on (not the PO). A blanket PO spans
     * fiscal years, so this is what lets a report show only the slice that
     * belongs to the selected year. A null FY falls back to the all-years
     * total.
     */
    public function committedTotalForFy(?string $fy): float
    {
        if ($fy === null) {
            return $this->committedTotal();
        }

        return (float) $this->lineItems()
            ->whereHas('order', fn ($query) => $query->where('fiscal_year', $fy))
            ->get()->sum->lineTotal();
    }

    /**
     * Invoiced subtotal booked in a single fiscal year, attributed by the
     * FY of the order each invoice sits on. A null FY is the all-years
     * total.
     */
    public function invoicedTotalForFy(?string $fy): float
    {
        if ($fy === null) {
            return $this->invoicedTotal();
        }

        return (float) $this->invoices()
            ->whereHas('order', fn ($query) => $query->where('fiscal_year', $fy))
            ->sum('subtotal');
    }

    /**
     * Budget left after committed spend, or null when no budget is set.
     */
    public function remaining(): ?float
    {
        if ($this->budget === null) {
            return null;
        }

        return (float) $this->budget - $this->committedTotal();
    }

    /**
     * Whether committed spend has exceeded the budget.
     */
    public function isOverBudget(): bool
    {
        return $this->budget !== null && $this->committedTotal() > (float) $this->budget;
    }
}
