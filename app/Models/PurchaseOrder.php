<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * A purchase order — the budget unit. One purchase order (the number issued
 * by the finance ERP) spans many vendor orders, which each carry invoices
 * and line items. Budget is tracked here; spend rolls up from the orders.
 */
class PurchaseOrder extends SnipeModel
{
    use HasFactory;
    use Loggable;
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
    ];

    protected $casts = [
        'order_date' => 'date',
    ];

    protected $searchableAttributes = ['po_number', 'title', 'fiscal_year', 'cost_center', 'status', 'notes'];

    protected $searchableRelations = [
        'supplier' => ['name'],
        'company' => ['name'],
    ];

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
     * Total actually billed against this PO: the cost of every line item
     * that has been assigned to an invoice.
     */
    public function invoicedTotal(): float
    {
        return (float) $this->lineItems()->whereNotNull('invoice_id')->get()->sum->lineTotal();
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
