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

    protected static function booted(): void
    {
        // Flipping the stage to agreement_sent (either via the explicit
        // Send for Signature button or a direct stage edit) auto-creates
        // the CheckoutAcceptance with the rendered EULA so the faculty
        // member sees it in Snipe's native signing UI. Idempotent: an
        // agreement that already has a linked acceptance is skipped.
        static::saved(function (FacultyAgreement $agreement) {
            if (
                $agreement->lifecycle_stage === 'agreement_sent'
                && empty($agreement->checkout_acceptance_id)
                && $agreement->asset_id
            ) {
                $agreement->sendForSignature();
            }
        });
    }

    /**
     * Merge variables substituted into the EULA body and the generated
     * PDF for this agreement. All keys are guaranteed to exist — missing
     * data renders as an empty string rather than leaving the {{...}}
     * placeholder in the final document.
     */
    public function mergeVariables(): array
    {
        $asset = $this->asset;
        $base = (float) ($this->base_program_price ?? 0);
        $device = (float) ($this->device_cost ?? 0);
        $top = (float) ($this->top_up_amount ?? max($device - $base, 0));
        $buyout = (float) ($this->buyout_cost ?? 0);

        return [
            'faculty_name' => (string) ($this->user?->full_name ?? ''),
            'asset_tag' => (string) ($asset?->asset_tag ?? ''),
            'serial' => (string) ($asset?->serial ?? ''),
            'model' => (string) ($asset?->model?->name ?? ''),
            'date' => now()->format('Y-m-d'),
            'pickup_date' => $this->deployed_at
                ? $this->deployed_at->format('Y-m-d')
                : now()->format('Y-m-d'),
            'base_price' => $this->formatMoney($base),
            'full_price' => $this->formatMoney($device),
            'upgrade_amount' => $this->formatMoney($top),
            'buyout_cost' => $this->formatMoney($buyout),
            'monthly_12' => $this->formatMoney($top > 0 ? $top / 12 : 0),
            'monthly_24' => $this->formatMoney($top > 0 ? $top / 24 : 0),
        ];
    }

    /**
     * Render the agreement-type-specific body with merge variables filled
     * in. Pulled from resources/lang/.../admin/faculty-agreements/eula.php
     * so non-engineers can update the legal text without code changes.
     */
    public function eulaBody(): string
    {
        $key = match ($this->agreement_type) {
            'pickup' => 'admin/faculty-agreements/eula.pickup_body',
            'upgrade' => 'admin/faculty-agreements/eula.upgrade_body',
            'lease_end_purchase' => 'admin/faculty-agreements/eula.lease_end_body',
            default => null,
        };

        if (! $key) {
            return '';
        }

        $template = (string) trans($key);

        foreach ($this->mergeVariables() as $var => $value) {
            $template = str_replace('{{'.$var.'}}', $value, $template);
        }

        return $template;
    }

    /**
     * Create the CheckoutAcceptance that drives the faculty signing UI.
     * The acceptance is attached to the agreement's asset and to the
     * faculty user, with the merge-rendered EULA captured in
     * `eula_text_override` so neither the asset's category nor any other
     * checkouts are affected. Returns the new acceptance, or null if the
     * agreement is missing the asset / user link.
     */
    public function sendForSignature(): ?CheckoutAcceptance
    {
        if (! $this->asset || ! $this->user) {
            return null;
        }

        if ($this->checkout_acceptance_id) {
            return CheckoutAcceptance::find($this->checkout_acceptance_id);
        }

        $acceptance = new CheckoutAcceptance;
        $acceptance->checkoutable()->associate($this->asset);
        $acceptance->assignedTo()->associate($this->user);
        $acceptance->eula_text_override = $this->eulaBody();
        $acceptance->save();

        $this->checkout_acceptance_id = $acceptance->id;
        if ($this->lifecycle_stage !== 'agreement_sent') {
            $this->lifecycle_stage = 'agreement_sent';
        }
        $this->saveQuietly();

        return $acceptance;
    }

    /**
     * Called from the CheckoutAccepted event listener when the faculty
     * member signs in Snipe. Captures the signed-PDF filename and bumps
     * the lifecycle to agreement_signed; downstream stage transitions
     * (deployed, in_repayment, paid_off) are still manual.
     */
    public function markSigned(CheckoutAcceptance $acceptance): void
    {
        $this->signed_at = $acceptance->accepted_at ?? now();
        $this->signed_pdf_path = $acceptance->stored_eula_file;
        $this->lifecycle_stage = 'agreement_signed';
        $this->saveQuietly();
    }

    private function formatMoney(float $value): string
    {
        return '$'.number_format($value, 2);
    }
}
