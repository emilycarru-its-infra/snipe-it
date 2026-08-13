<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vendor invoice against an order. A single order (e.g. a CDW order)
 * is commonly billed across several invoices, so line items each point
 * at the invoice they were billed on.
 */
class OrderInvoice extends Model
{
    use HasFactory;

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
    ];

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
     * The [start, end] bounds of a fiscal year. ECU fiscal years run April to
     * March, so FY2025-26 spans 2025-04-01 to 2026-03-31. Accepts both the
     * four-digit `FY2025-26` and two-digit `FY25-26` forms.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private static function fiscalYearRange(string $fy): ?array
    {
        $fy = trim($fy);

        if (preg_match('/(\d{4})\s*-\s*\d{2}$/', $fy, $m)) {
            $start = (int) $m[1];
        } elseif (preg_match('/(\d{2})\s*-\s*\d{2}$/', $fy, $m)) {
            $start = 2000 + (int) $m[1];
        } else {
            return null;
        }

        return [
            Carbon::create($start, 4, 1)->startOfDay(),
            Carbon::create($start + 1, 3, 31)->endOfDay(),
        ];
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
}
