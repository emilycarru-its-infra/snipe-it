<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

class Order extends SnipeModel
{
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
        'supplier_id',
        'company_id',
        'order_date',
        'expected_date',
        'received_date',
        'order_cost',
        'notes',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'received_date' => 'date',
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
     * Establishes the order -> line items relationship
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * Establishes the order -> shipments relationship
     */
    public function shipments()
    {
        return $this->hasMany(OrderShipment::class, 'order_id');
    }

    /**
     * Establishes the order -> supplier relationship
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * Establishes the order -> company relationship
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Establishes the order -> admin user relationship
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
