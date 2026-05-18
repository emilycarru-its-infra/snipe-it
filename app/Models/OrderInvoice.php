<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A vendor invoice against an order. A single order (e.g. a CDW order)
 * is commonly billed across several invoices, so line items each point
 * at the invoice they were billed on.
 */
class OrderInvoice extends Model
{
    use HasFactory;

    protected $table = 'order_invoices';

    protected $fillable = [
        'order_id',
        'invoice_number',
        'invoice_date',
        'subtotal',
        'tax_gst',
        'tax_pst',
        'shipping',
        'total',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'date',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
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
}
