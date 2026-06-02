<?php

namespace App\Models;

use App\Helpers\Helper;
use App\Mail\UserAgreementSignatureRequestMail;
use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Watson\Validating\ValidatingTrait;

/**
 * User Agreement Program agreement — a single record covers any of the
 * three agreement types (new laptop pickup, paid upgrade above the
 * program base price, outright purchase incl. lease-end buyouts)
 * through their shared lifecycle: eligible → quoted → agreement_sent
 * → agreement_signed → deployed → in_repayment → paid_off
 * (or closed_buyout, for purchases).
 */
class UserAgreement extends SnipeModel
{
    use HasFactory;
    use Loggable;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'user_agreements';

    public const AGREEMENT_TYPES = [
        'pickup',
        'upgrade',
        'purchase',
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
        'cancelled',
    ];

    public const STAGE_LABEL_CLASS = [
        'eligible'         => 'default',
        'quoted'           => 'info',
        'agreement_sent'   => 'warning',
        'agreement_signed' => 'primary',
        'deployed'         => 'success',
        'in_repayment'     => 'primary',
        'paid_off'         => 'success',
        'closed_buyout'    => 'success',
        'closed'           => 'default',
        'cancelled'        => 'danger',
    ];

    /**
     * Lifecycle stages considered "open" — i.e. an agreement in any of
     * these still occupies the (user, asset, type) slot from the
     * auto-creators' point of view. Mirrors isOpen() but as a usable
     * array for whereIn() queries so callers don't have to redefine the
     * closed-stage list (drift risk).
     */
    public const OPEN_LIFECYCLE_STAGES = [
        'eligible',
        'quoted',
        'agreement_sent',
        'agreement_signed',
        'deployed',
        'in_repayment',
    ];

    public const PAYMENT_METHODS = [
        'payroll_deduction',
        'pay_in_full',
    ];

    protected $rules = [
        'agreement_type' => 'required|string|in:pickup,upgrade,purchase',
        'user_id' => 'nullable|exists:users,id',
        'asset_id' => 'nullable|exists:assets,id',
        'lifecycle_stage' => 'required|string|in:eligible,quoted,agreement_sent,agreement_signed,deployed,in_repayment,paid_off,closed_buyout,closed,cancelled',
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
        'terms_accepted_at',
        'signed_pdf_path',
        'sent_to_payroll_at',
        'deployed_at',
        'closed_at',
        'old_asset_tag',
        'old_serial',
        'lease_contract',
        'checkout_acceptance_id',
        'notes',
        'reminders_sent',
        'last_reminder_sent_at',
        'cancelled_at',
        'cancelled_by_id',
        'cancellation_reason',
        'sent_to_payroll_by_id',
    ];

    protected $casts = [
        'pdf_generated_at' => 'datetime',
        'signed_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'sent_to_payroll_at' => 'datetime',
        'deployed_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
        'reminders_sent' => 'integer',
        'cancelled_at' => 'datetime',
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

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_id')->withTrashed();
    }

    public function sentToPayrollBy()
    {
        return $this->belongsTo(User::class, 'sent_to_payroll_by_id')->withTrashed();
    }

    /**
     * Resolve the `lease_contract` string (a contract_number or name)
     * against the Contract table so the ledger can hyperlink to it.
     * Returns null when the string doesn't match anything.
     */
    public function originatingContract(): ?Contract
    {
        if (empty($this->lease_contract)) {
            return null;
        }

        return Contract::where('contract_number', $this->lease_contract)
            ->orWhere('name', $this->lease_contract)
            ->first();
    }

    public function cancel(?int $cancelledById, ?string $reason = null): bool
    {
        $this->lifecycle_stage     = 'cancelled';
        $this->cancelled_at        = now();
        $this->cancelled_by_id     = $cancelledById;
        $this->cancellation_reason = $reason;

        return $this->save();
    }

    public function markSentToPayroll(?int $byUserId): bool
    {
        $this->sent_to_payroll_at    = now();
        $this->sent_to_payroll_by_id = $byUserId;

        return $this->save();
    }

    public function checkoutAcceptance()
    {
        return $this->belongsTo(CheckoutAcceptance::class, 'checkout_acceptance_id');
    }

    /**
     * Display name used by Searchable / nav cards — no `name` column on
     * this table so fall back to a synthetic "<type> for <user>".
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim(($this->agreement_type ?? '').' '.($this->user?->full_name ?? 'unassigned'))
        );
    }

    /**
     * Total contract value finance cares about — top-up for upgrades,
     * buyout for purchases, device cost for plain pickups.
     */
    public function contractValue(): float
    {
        return match ($this->agreement_type) {
            'upgrade' => (float) $this->top_up_amount,
            'purchase' => (float) $this->buyout_cost,
            default => (float) ($this->device_cost ?? 0.0),
        };
    }

    public function isOpen(): bool
    {
        return in_array($this->lifecycle_stage, self::OPEN_LIFECYCLE_STAGES, true);
    }

    public function isAwaitingSignature(): bool
    {
        return in_array($this->lifecycle_stage, ['quoted', 'agreement_sent'], true);
    }

    protected static function booted(): void
    {
        // Flipping the stage to agreement_sent (either via the explicit
        // Send for Signature button or a direct stage edit) auto-creates
        // the CheckoutAcceptance with the rendered EULA so the user
        // member sees it in Snipe's native signing UI. Idempotent: an
        // agreement that already has a linked acceptance is skipped.
        static::saved(function (UserAgreement $agreement) {
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
     *
     * The 'faculty_name' key is intentionally retained: it matches the
     * {{faculty_name}} placeholder in the .docx templates stored in
     * SharePoint. Renaming the key here without also updating the
     * templates would break PDF generation. If the templates are ever
     * updated, swap to 'user_name' and update both sides together.
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
            'payment_phrase' => $this->paymentPhrase(),
        ];
    }

    /**
     * Human-readable description of the chosen payment method, slotted
     * into agreement bodies via the {{payment_phrase}} merge variable.
     * Falls back to a neutral "as agreed" when no method is set yet, so
     * a preview rendered before the user picks one still produces a
     * grammatical sentence.
     */
    private function paymentPhrase(): string
    {
        return match ($this->payment_method) {
            'pay_in_full'        => 'via a one-time payment',
            'payroll_deduction'  => 'via payroll deductions',
            default              => 'as agreed',
        };
    }

    /**
     * Resolve the editable title or body for an agreement type, preferring
     * the admin-set value under Settings → Agreements
     * (settings.agreement_<type>_<part>) and falling back to the
     * admin/user-agreements/eula.php lang strings when that column is blank.
     * A non-type returns an empty string. $part is 'title' or 'body'.
     */
    public static function resolveEulaText(string $type, string $part): string
    {
        if (! in_array($type, self::AGREEMENT_TYPES, true)) {
            return '';
        }

        $override = trim((string) (Setting::getSettings()->{'agreement_'.$type.'_'.$part} ?? ''));

        return $override !== ''
            ? $override
            : (string) trans('admin/user-agreements/eula.'.$type.'_'.$part);
    }

    /**
     * The PDF / signing-page heading for an agreement type, admin-editable
     * with a lang-file fallback. Static so the show view can render it
     * without an instance.
     */
    public static function eulaTitle(string $type): string
    {
        return self::resolveEulaText($type, 'title');
    }

    /**
     * Render the agreement-type-specific body with merge variables filled
     * in. The raw copy comes from Settings → Agreements (admin-editable) or
     * the eula.php lang fallback via resolveEulaText().
     */
    public function eulaBody(): string
    {
        $template = self::resolveEulaText($this->agreement_type, 'body');

        if ($template === '') {
            return '';
        }

        foreach ($this->mergeVariables() as $var => $value) {
            $template = str_replace('{{'.$var.'}}', $value, $template);
        }

        return $template;
    }

    /**
     * Create the CheckoutAcceptance that drives the agreement signing UI.
     * The acceptance is attached to the agreement's asset and to the
     * assigned user, with the merge-rendered EULA captured in
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

        // Mail the assigned user with the sign link + attached unsigned
        // PDF (if pre-rendered by snipeit:user-pregen-pdfs). Wrapped in
        // try/catch so a flaky SMTP run can't roll back the stage
        // transition — that's a higher-cost recovery than a missed email.
        try {
            if ($this->user && $this->user->email) {
                Mail::to($this->user->email)->send(new UserAgreementSignatureRequestMail($this));
            }
        } catch (\Throwable $e) {
            Log::error('user agreement signature-request email failed for FA#'.$this->id, ['exception' => $e]);
        }

        return $acceptance;
    }

    /**
     * Called from the CheckoutAccepted event listener when the user
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

    /**
     * Render the unsigned agreement as PDF bytes (in-memory). Branded
     * per-type layouts live in {@see \App\Services\UserAgreements\PdfRenderer};
     * this method is the single entry point for the controller's
     * preview/download endpoint and the bulk pre-gen artisan command.
     */
    public function renderUnsignedPdfBytes(): string
    {
        return app(\App\Services\UserAgreements\PdfRenderer::class)->render($this);
    }

    /**
     * Render the unsigned PDF and persist it to private storage so it's
     * ready to attach to a signature request without re-rendering. Path
     * convention: `private_uploads/user-agreements/{id}-{type}.pdf`.
     * Returns the relative storage path (or null if dependencies are
     * incomplete — no asset / no user).
     */
    public function storeUnsignedPdf(): ?string
    {
        if (! $this->asset || ! $this->user) {
            return null;
        }

        $relative = 'private_uploads/user-agreements/'.$this->id.'-'.$this->agreement_type.'.pdf';
        Storage::put($relative, $this->renderUnsignedPdfBytes());

        $this->pdf_path = $relative;
        $this->pdf_generated_at = now();
        $this->saveQuietly();

        return $relative;
    }
}
