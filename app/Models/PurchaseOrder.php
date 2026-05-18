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
     * Total actually billed against this PO across all invoices.
     */
    public function invoicedTotal(): float
    {
        $total = 0.0;
        foreach ($this->orders as $order) {
            $total += (float) $order->invoices->sum('total');
        }

        return $total;
    }

    /**
     * Committed spend: billed invoice totals where an order has been
     * invoiced, otherwise the order's line-item estimate. This is the
     * figure compared against the budget.
     */
    public function committedTotal(): float
    {
        $total = 0.0;
        foreach ($this->orders as $order) {
            $invoiced = (float) $order->invoices->sum('total');
            $total += $invoiced > 0 ? $invoiced : $this->orderLineItemTotal($order);
        }

        return $total;
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

    private function orderLineItemTotal(Order $order): float
    {
        return $order->items->sum(
            fn ($item) => ((float) $item->unit_cost * (int) $item->quantity) + (float) $item->warranty_cost
        );
    }
}
