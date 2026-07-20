<?php

namespace App\Models;

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
     */
    public function expectedSubtotal(): float
    {
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

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function isPendingApproval(): bool
    {
        return ($this->approval_status ?? 'pending') === 'pending';
    }
}
