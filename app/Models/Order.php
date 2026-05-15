<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
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
        'tracking_number' => 'nullable|string|max:191',
        'tracking_carrier' => 'nullable|string|max:191',
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
        'tracking_number',
        'tracking_carrier',
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
    protected $searchableAttributes = ['order_number', 'tracking_number', 'tracking_carrier', 'status', 'notes'];

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
}
