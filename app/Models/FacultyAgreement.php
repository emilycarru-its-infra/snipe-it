<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * Faculty Laptop Program agreement — a single record covers any of the
 * three form types (new laptop pickup, paid upgrade above the program
 * base price, lease-end buyout) through their shared lifecycle:
 * eligible → quoted → agreement_sent → agreement_signed → deployed →
 * in_repayment → paid_off (or closed_buyout, for lease-end purchases).
 */
class FacultyAgreement extends SnipeModel
{
    use HasFactory;
    use Loggable;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'faculty_agreements';

    public const AGREEMENT_TYPES = [
        'pickup',
        'upgrade',
        'lease_end_purchase',
    ];

    public const LIFECYCLE_STAGES = [
        'eligible',
        'quoted',
        'agreement_sent',
        'agreement_signed',
        'deployed',
        'in_repayment',
        'paid_off',
        'closed_buyout',
        'closed',
    ];

    public const PAYMENT_METHODS = [
        'payroll_deduction',
        'pay_in_full',
    ];

    protected $rules = [
        'agreement_type' => 'required|string|in:pickup,upgrade,lease_end_purchase',
        'user_id' => 'nullable|exists:users,id',
        'asset_id' => 'nullable|exists:assets,id',
        'lifecycle_stage' => 'required|string|in:eligible,quoted,agreement_sent,agreement_signed,deployed,in_repayment,paid_off,closed_buyout,closed',
        'base_program_price' => 'nullable|numeric',
        'device_cost' => 'nullable|numeric',
        'top_up_amount' => 'nullable|numeric',
        'buyout_cost' => 'nullable|numeric',
        'payment_method' => 'nullable|string|in:payroll_deduction,pay_in_full',
        'installment_count' => 'nullable|integer|min:1|max:120',
        'installment_amount' => 'nullable|numeric',
        'balance_paid' => 'nullable|numeric',
        'balance_remaining' => 'nullable|numeric',
        'old_asset_tag' => 'nullable|string|max:191',
        'old_serial' => 'nullable|string|max:191',
        'lease_contract' => 'nullable|string|max:191',
        'notes' => 'nullable|string|max:65535',
    ];

    protected $injectUniqueIdentifier = true;

    protected $fillable = [
        'agreement_type',
        'user_id',
        'asset_id',
        'lifecycle_stage',
        'base_program_price',
        'device_cost',
        'top_up_amount',
        'buyout_cost',
        'payment_method',
        'installment_count',
        'installment_amount',
        'balance_paid',
        'balance_remaining',
        'pdf_generated_at',
        'pdf_path',
        'signed_at',
        'signed_pdf_path',
        'sent_to_payroll_at',
        'deployed_at',
        'closed_at',
        'old_asset_tag',
        'old_serial',
        'lease_contract',
        'checkout_acceptance_id',
        'notes',
    ];

    protected $casts = [
        'pdf_generated_at' => 'datetime',
        'signed_at' => 'datetime',
        'sent_to_payroll_at' => 'datetime',
        'deployed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected $searchableAttributes = ['agreement_type', 'lifecycle_stage', 'old_asset_tag', 'old_serial', 'lease_contract', 'notes'];

    protected $searchableRelations = [
        'user' => ['first_name', 'last_name', 'username', 'email'],
        'asset' => ['asset_tag', 'serial', 'name'],
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function checkoutAcceptance()
    {
        return $this->belongsTo(CheckoutAcceptance::class, 'checkout_acceptance_id');
    }

    /**
     * Display name used by Searchable / nav cards — no `name` column on
     * this table so fall back to a synthetic "<type> for <faculty>".
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim(($this->agreement_type ?? '').' '.($this->user?->full_name ?? 'unassigned'))
        );
    }

    /**
     * Total contract value finance cares about — top-up for upgrades,
     * buyout for lease-end purchases, device cost for plain pickups.
     */
    public function contractValue(): float
    {
        return match ($this->agreement_type) {
            'upgrade' => (float) $this->top_up_amount,
            'lease_end_purchase' => (float) $this->buyout_cost,
            default => (float) ($this->device_cost ?? 0.0),
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this->lifecycle_stage, ['paid_off', 'closed_buyout', 'closed'], true);
    }

    public function isAwaitingSignature(): bool
    {
        return in_array($this->lifecycle_stage, ['quoted', 'agreement_sent'], true);
    }
}
