<?php

namespace App\Models;

use App\Helpers\Helper;
use App\Models\Traits\HasStableRouteKey;
use App\Services\Leasing\OkayToPay;
use App\Services\Leasing\OkayToPayHistory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * @property-read OkayToPayDelivery|null $okayToPayDelivery
 *
 * A vendor invoice against an order. A single order (e.g. a CDW order)
 * is commonly billed across several invoices, so line items each point
 * at the invoice they were billed on.
 */
class OrderInvoice extends Model
{
    use HasFactory;
    use HasStableRouteKey;

    protected $table = 'order_invoices';

    public const APPROVAL_STATUSES = [
        'pending',
        'approved',
        'disputed',
    ];

    public const INVOICE_TYPES = [
        'regular',
        'buyout',
        'credit',
        'termination',
    ];

    /**
     * Invoice types that move money without shipping anything, so they carry
     * no line items by design — the ingest endpoint explicitly exempts them
     * from the items requirement.
     */
    public const ADJUSTMENT_TYPES = [
        'buyout',
        'credit',
        'termination',
    ];

    /**
     * Where an invoice can stand in accounts payable, as the finance system
     * reports it. Recorded, not enforced: the finance system owns payment.
     */
    public const AP_STATUSES = [
        'entered',
        'scheduled',
        'paid',
        'partially_paid',
        'on_hold',
        'voided',
    ];

    public const ATTESTATION_TYPES = [
        'vendor_invoice',
        'lessor_okp',
    ];

    protected $fillable = [
        'order_id',
        'purchase_order_id',
        'invoice_number',
        'invoice_date',
        'subtotal',
        'tax_gst',
        'tax_pst',
        'shipping',
        'total',
        'notes',
        'approval_status',
        'approved_at',
        'approved_by',
        'is_final_invoice',
        'usage_tag',
        'invoice_type',
        'contract_reference',
        'attestation_type',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'approved_at' => 'datetime',
        'is_final_invoice' => 'boolean',
        'okp_reasons' => 'array',
        'okp_send_after' => 'datetime',
        'okp_reminded_at' => 'datetime',
        'okp_sent_at' => 'datetime',
        'ap_paid_at' => 'date',
        'ap_paid_amount' => 'decimal:2',
        'ap_synced_at' => 'datetime',
    ];

    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
    }

    protected static function booted(): void
    {
        static::updating(fn (OrderInvoice $invoice) => OkayToPayHistory::protect($invoice, original: true));
        static::deleting(fn (OrderInvoice $invoice) => OkayToPayHistory::protect($invoice, original: true));
        static::saved(fn (OrderInvoice $invoice) => OkayToPayHistory::protect($invoice));
    }

    /** @return HasOne<OkayToPayDelivery, $this> */
    public function okayToPayDelivery(): HasOne
    {
        return $this->hasOne(OkayToPayDelivery::class, 'invoice_id');
    }

    /**
     * The invoices the signed-in user's company may see. An invoice belongs
     * to its order's company (a deleted order still owns its invoices), or —
     * when its order record is gone — its purchase order's; the purchase
     * order it is charged to must be visible too, as on the
     * workflow-observations feed. One with neither parent belongs to nobody
     * and is left to superusers. Without full multiple company support, or
     * for a superuser, everything.
     */
    public function scopeVisibleToCurrentUser($query)
    {
        $user = auth()->user();
        if (Setting::getSettings()?->full_multiple_companies_support != '1' || ! $user || $user->isSuperUser()) {
            return $query;
        }

        return $query
            ->where(fn ($charged) => $charged
                ->whereNull('order_invoices.purchase_order_id')
                ->orWhereHas('purchaseOrder', fn ($po) => Company::scopeCompanyables($po)))
            ->where(fn ($owner) => $owner
                ->whereHas('order', fn ($order) => Company::scopeCompanyables($order->withTrashed()))
                ->orWhere(fn ($poOnly) => $poOnly
                    ->whereDoesntHave('order', fn ($order) => $order->withTrashed())
                    ->whereHas('purchaseOrder', fn ($po) => Company::scopeCompanyables($po))));
    }

    /**
     * Whether the signed-in user's company may see this invoice; see
     * scopeVisibleToCurrentUser().
     */
    public function isVisibleToCurrentUser(): bool
    {
        return static::query()->whereKey($this->getKey())->visibleToCurrentUser()->exists();
    }

    /**
     * Scope invoices to a fiscal year.
     *
     * Attribution follows the booking order — a blanket purchase order spans
     * fiscal years, so the order is the transaction, not the parent PO — but
     * falls back to the invoice's own `invoice_date` when that order carries
     * no `fiscal_year`. CDW-ingested orders don't always get one stamped (the
     * webhook used to leave it null), and without the fallback those invoices
     * vanish from any year-scoped view despite having a real invoice date.
     *
     * This lives on the model because the rule had drifted between callers:
     * the procurement dashboard applied the fallback and the pipeline's
     * reconciling column did not, so a year could report "$33,880.19 invoiced
     * · 2 invoices pending approval" in the chevron above a column reading
     * "Nothing here yet". One definition, one behaviour.
     *
     * A null fiscal year is a no-op (all years).
     */
    public function scopeForFiscalYear($query, ?string $fy)
    {
        if ($fy === null || trim($fy) === '' || strtolower(trim($fy)) === 'all') {
            return $query;
        }

        $range = self::fiscalYearRange($fy);

        return $query->where(function ($q) use ($fy, $range) {
            $q->whereHas('order', fn ($o) => $o->where('fiscal_year', $fy));

            if ($range) {
                $q->orWhere(fn ($alt) => $alt
                    ->whereDoesntHave('order', fn ($o) => $o->whereNotNull('fiscal_year')->where('fiscal_year', '!=', ''))
                    ->whereBetween('invoice_date', $range));
            }
        });
    }

    /**
     * The [start, end] bounds of a fiscal year; see App\Services\FiscalYear.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private static function fiscalYearRange(string $fy): ?array
    {
        return Helper::fiscalYearRange($fy);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * The purchase order this invoice is charged to. A vendor order can be
     * split across purchase orders, so the invoice carries its own PO.
     *
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * Line items billed on this invoice.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'invoice_id');
    }

    /**
     * Total tax (GST + PST) recorded on the invoice.
     */
    public function taxTotal(): float
    {
        return (float) $this->tax_gst + (float) $this->tax_pst;
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Expected pre-tax amount derived from the line items billed on this
     * invoice. The CDW invoice subtotal should match this — the difference
     * is the "variance" finance asks about every month.
     *
     * A buyout, credit or termination commits or returns money without
     * shipping a device, so it has no line items to derive anything from and
     * its own subtotal is the expectation. Deriving zero for those made the
     * approval queue flag all three of them in red every month for a variance
     * equal to their whole amount, which is noise finance has to look past to
     * find the invoices that are genuinely wrong.
     */
    public function expectedSubtotal(): float
    {
        if ($this->isAdjustment()) {
            return (float) $this->subtotal;
        }

        return (float) $this->items->sum->lineTotal();
    }

    /**
     * Vendor subtotal minus the expected line-item total. A positive
     * variance means CDW billed more than expected, negative means less.
     */
    public function variance(): float
    {
        return (float) $this->subtotal - $this->expectedSubtotal();
    }

    /**
     * Whether this invoice moves money rather than recording a shipment.
     */
    public function isAdjustment(): bool
    {
        return in_array($this->invoice_type, self::ADJUSTMENT_TYPES, true);
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function isPendingApproval(): bool
    {
        return ($this->approval_status ?? 'pending') === 'pending';
    }

    /**
     * Where the lessor's OK to pay stands for this invoice: blank when it
     * does not apply, otherwise queued (with its send time), held (with why)
     * or sent. See App\Services\Leasing\OkayToPay.
     */
    public function okayToPayLabel(): string
    {
        if ($this->okp_status !== OkayToPay::SENT && OkayToPayHistory::blocked($this)) {
            return trans('admin/purchase-orders/general.okp_reason_historical_settlement');
        }
        $when = fn ($date) => $date ? $date->timezone(config('app.timezone'))->format('M j, g:i A') : '';

        return match ($this->okp_status) {
            OkayToPay::QUEUED => trans('admin/purchase-orders/general.okp_status_queued', ['date' => $when($this->okp_send_after)]),
            OkayToPay::SENDING, OkayToPay::UNCERTAIN => trans('admin/purchase-orders/general.okp_reason_delivery_reserved'),
            OkayToPay::HELD => trim(trans('admin/purchase-orders/general.okp_status_held').' — '.implode(' ', (array) $this->okp_reasons), ' —'),
            OkayToPay::SENT => trans('admin/purchase-orders/general.okp_status_sent', ['date' => $when($this->okp_sent_at)]),
            default => '',
        };
    }

    /**
     * Whether accounts payable has reported anything for this invoice.
     */
    public function hasPaymentRecord(): bool
    {
        return filled($this->ap_status) || filled($this->ap_voucher_number) || $this->ap_paid_at !== null
            || $this->ap_paid_amount !== null;
    }

    /**
     * What was paid minus what was invoiced; null until a paid amount is
     * recorded. Anything other than zero is worth a look — a short payment,
     * a duplicate, or a credit applied on the finance side.
     */
    public function paymentDifference(): ?float
    {
        if ($this->ap_paid_amount === null) {
            return null;
        }

        return round((float) $this->ap_paid_amount - (float) $this->total, 2);
    }

    /**
     * Subtotal + GST + PST + shipping, against the total the vendor printed.
     * Null when the invoice carries no total to check.
     */
    public function componentsDifference(): ?float
    {
        if ($this->total === null) {
            return null;
        }

        return round((float) $this->total
            - ((float) $this->subtotal + (float) $this->tax_gst + (float) $this->tax_pst + (float) $this->shipping), 2);
    }

    /**
     * Its page is addressed by the vendor's invoice number; see
     * HasStableRouteKey. Two suppliers can issue the same number, and then
     * the id is used instead.
     *
     * @return array<int, string>
     */
    public function stableRouteKeyColumns(): array
    {
        return ['invoice_number'];
    }
}
