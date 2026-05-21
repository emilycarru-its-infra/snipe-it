<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * A lease schedule document issued by a lessor (CSI Leasing, Macquarie
 * / CCA Financial) for a specific batch of devices. The schedule passes
 * through a short lifecycle — draft, awaiting_signature, signed, active
 * — while it's reviewed, budgeted, and signed by Mark / Viktor before
 * being returned to the lessor and becoming the live lease.
 *
 * schedule_ref matches the Lease Contract ID custom field on assets so
 * existing reports keep working without a schema migration.
 */
class LeaseSchedule extends SnipeModel
{
    use HasFactory;
    use Loggable;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'lease_schedules';

    public const LIFECYCLE_STAGES = [
        'draft',
        'awaiting_signature',
        'signed',
        'active',
        'cancelled',
    ];

    public const OPEN_STAGES = [
        'draft',
        'awaiting_signature',
    ];

    protected $rules = [
        'schedule_ref' => 'required|string|max:191',
        'lessor' => 'nullable|string|max:191',
        'lease_type' => 'nullable|string|max:191',
        'term_months' => 'nullable|integer|min:1|max:240',
        'received_at' => 'nullable|date',
        'expected_acquisition_cost' => 'nullable|numeric',
        'expected_asset_count' => 'nullable|integer|min:0',
        'usage_tag' => 'nullable|string|max:191',
        'lifecycle_stage' => 'required|string|in:draft,awaiting_signature,signed,active,cancelled',
        'signed_at' => 'nullable|date',
        'vendor_on_hold' => 'boolean',
        'annexure_a_path' => 'nullable|string|max:191',
        'notes' => 'nullable|string|max:65535',
    ];

    protected $injectUniqueIdentifier = true;

    protected $fillable = [
        'schedule_ref',
        'lessor',
        'lease_type',
        'term_months',
        'received_at',
        'expected_acquisition_cost',
        'expected_asset_count',
        'usage_tag',
        'lifecycle_stage',
        'signed_at',
        'signed_by',
        'vendor_on_hold',
        'annexure_a_path',
        'notes',
    ];

    protected $casts = [
        'received_at' => 'date',
        'signed_at' => 'datetime',
        'vendor_on_hold' => 'boolean',
    ];

    protected $searchableAttributes = ['schedule_ref', 'lessor', 'lease_type', 'usage_tag', 'lifecycle_stage', 'notes'];

    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signed_by')->withTrashed();
    }

    /**
     * Display name used by Searchable — no `name` column on this table,
     * so fall back to the schedule reference.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->schedule_ref,
        );
    }

    public function isOpen(): bool
    {
        return in_array($this->lifecycle_stage, self::OPEN_STAGES, true);
    }

    public function isSigned(): bool
    {
        return in_array($this->lifecycle_stage, ['signed', 'active'], true);
    }

    /**
     * Whole days the schedule has been sitting in the queue waiting on
     * a signature — for the chase view. Returns 0 if not yet received
     * or already signed.
     */
    public function daysPending(): int
    {
        if (! $this->isOpen() || ! $this->received_at) {
            return 0;
        }

        return (int) $this->received_at->diffInDays(now());
    }
}
