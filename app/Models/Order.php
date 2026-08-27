<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use App\Models\Traits\PlacesVendorOrders;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $funding_account
 * @property string|null $lease_schedule
 * @property string|null $quote_number
 * @property float|string|null $quote_total
 * @property \Illuminate\Support\Carbon|null $quote_expires_at
 * @property \Illuminate\Support\Carbon|null $quote_confirmed_at
 * @property \Illuminate\Support\Carbon|null $vendor_sent_at
 * @property \Illuminate\Support\Carbon|null $vendor_changes_at
 * @property string|null $vendor_changes_notes
 * @property string|null $vendor_order_number
 * @property string|null $order_cc
 * @property string|null $order_cc_users
 * @property string|null $order_number
 * @property int|null $purchase_order_id
 * @property string|null $status
 */
class Order extends SnipeModel
{
    use PlacesVendorOrders;

    /** A catalog row unchecked against the vendor's list for this long is flagged on the send. */
    public const PART_NUMBER_STALE_DAYS = 92;

    use HasFactory;
    use Loggable;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'orders';

    /**
     * Order-level lifecycle statuses, roughly chronological. An order is
     * partially_received when some line items have arrived but not all.
     */
    public const STATUSES = [
        'ordered',
        'shipped',
        'partially_received',
        'received',
        'cancelled',
    ];

    protected $rules = [
        'order_number' => 'required|string|max:191',
        'status' => 'required|string|in:ordered,shipped,partially_received,received,cancelled',
        'is_planned' => 'boolean',
        'fiscal_year' => 'nullable|string|max:191',
        'supplier_id' => 'nullable|exists:suppliers,id',
        'company_id' => 'nullable|exists:companies,id',
        'order_date' => 'nullable|date',
        'expected_date' => 'nullable|date',
        'received_date' => 'nullable|date',
        'order_cost' => 'nullable|numeric',
        'notes' => 'nullable|string|max:65535',
    ];

    protected $injectUniqueIdentifier = true;

    protected $fillable = [
        'order_number',
        'status',
        'is_planned',
        'fiscal_year',
        'purchase_order_id',
        'supplier_id',
        'company_id',
        'order_date',
        'expected_date',
        'received_date',
        'order_cost',
        'notes',
        // The vendor loop: the account the order is placed against, the
        // quote that came back, when it went and what they answered. Facts
        // about this order, not about the budget it draws on — a purchase
        // order sees several of these through a year.
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
        'order_cc_users',
    ];

    protected $casts = [
        'is_planned' => 'boolean',
        'order_date' => 'date',
        'expected_date' => 'date',
        'received_date' => 'date',
        'quote_expires_at' => 'date',
        'quote_confirmed_at' => 'datetime',
        'vendor_sent_at' => 'datetime',
        'vendor_changes_at' => 'datetime',
    ];

    /**
     * The attributes that should be included when searching the model.
     *
     * @var array
     */
    protected $searchableAttributes = ['order_number', 'status', 'notes'];

    /**
     * The relations and their attributes that should be included when searching the model.
     *
     * @var array
     */
    protected $searchableRelations = [
        'supplier' => ['name'],
        'company' => ['name'],
    ];

    /**
     * The lines the vendor is sent: this order's own. Through the loaded
     * relation when there is one, so this works on a model that was never
     * saved — the email previewer renders the order mail against sample
     * objects with their relations set by hand.
     */
    public function vendorOrderLines()
    {
        if ($this->relationLoaded('items')) {
            return $this->getRelation('items')->values();
        }

        return $this->exists ? $this->items()->with('catalogItem')->orderBy('id')->get()->values() : collect();
    }

    /**
     * The number the vendor bills against — the purchase order's — or our own
     * when an order was raised without one. What every email and CSV calls
     * this order.
     */
    public function reference(): string
    {
        return $this->purchaseOrder?->po_number ?: (string) $this->order_number;
    }

    /**
     * Whoever asked for this equipment through the store: their request was
     * folded into the purchase order this draws on, and this is the thread
     * that tells them it was placed.
     *
     * @return array<int, string>
     */
    public function storeOrderRequesterEmails(): array
    {
        return $this->purchaseOrder?->storeOrderRequesterEmails() ?? [];
    }

    /**
     * Establishes the order -> line items relationship
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * Planned orders are forecasts of future spend, not commitments.
     */
    public function scopePlanned($query)
    {
        return $query->where('is_planned', true);
    }

    /**
     * Actual orders are real commitments, as opposed to planned forecasts.
     */
    public function scopeActual($query)
    {
        return $query->where('is_planned', false);
    }

    /**
     * Establishes the order -> shipments relationship
     *
     * @return HasMany<OrderShipment, $this>
     */
    public function shipments()
    {
        return $this->hasMany(OrderShipment::class, 'order_id');
    }

    /**
     * Establishes the order -> invoices relationship
     *
     * @return HasMany<OrderInvoice, $this>
     */
    public function invoices()
    {
        return $this->hasMany(OrderInvoice::class, 'order_id');
    }

    /**
     * Establishes the order -> purchase order relationship
     *
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * Establishes the order -> supplier relationship
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * Establishes the order -> company relationship
     *
     * @return BelongsTo<Company, $this>
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Establishes the order -> admin user relationship
     *
     * @return BelongsTo<User, $this>
     */
    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * An order has no `name` column, so the shared display_name accessor
     * (which returns `name`) would be empty. Use the order number instead.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->order_number,
        );
    }

    /**
     * Re-derive the order status from its line items. Receiving is tracked
     * per line item; this rolls that up to the order. A cancelled order is
     * a manual terminal state and is left untouched.
     *
     * Saved quietly so it doesn't re-fire model events or validation.
     */
    public function recalculateStatus(): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        $total = $this->items()->count();

        if ($total === 0) {
            return;
        }

        $received = $this->items()->whereNotNull('received_at')->count();

        if ($received >= $total) {
            $status = 'received';
        } elseif ($received > 0) {
            $status = 'partially_received';
        } elseif ($this->shipments()->whereNotNull('shipped_date')->exists()) {
            $status = 'shipped';
        } else {
            $status = 'ordered';
        }

        if ($this->status !== $status) {
            $this->status = $status;
            $this->saveQuietly();
        }
    }
}
