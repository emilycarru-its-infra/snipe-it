<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * A logged decision about an expiring lease — whether to buy the
 * equipment out, return it, extend the lease or replace it. Keeps the
 * buyout-vs-return reasoning in one place instead of scattered emails.
 */
class LeaseDecision extends SnipeModel
{
    use HasFactory;
    use Loggable;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'lease_decisions';

    public const DECISION_TYPES = [
        'buyout',
        'return',
        'extend',
        'replace',
    ];

    public const STATUSES = [
        'pending',
        'approved',
        'completed',
        'cancelled',
    ];

    protected $rules = [
        'contract_reference' => 'required|string|max:191',
        'asset_id' => 'nullable|integer|exists:assets,id',
        'decision_type' => 'nullable|string|in:buyout,return,extend,replace',
        'decision_date' => 'nullable|date',
        'amount' => 'nullable|numeric',
        'status' => 'nullable|string|in:pending,approved,completed,cancelled',
        'notes' => 'nullable|string|max:65535',
    ];

    protected $injectUniqueIdentifier = true;

    protected $fillable = [
        'contract_reference',
        'asset_id',
        'decision_type',
        'decision_date',
        'amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'decision_date' => 'date',
    ];

    protected $searchableAttributes = ['contract_reference', 'decision_type', 'status', 'notes'];

    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * The specific asset this decision targets, when it is a per-serial
     * disposition. Null for contract-level decisions.
     */
    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * No `name` column — use the contract reference for display.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->contract_reference,
        );
    }
}
