<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of a CSI (MyCSI) invoice. Upserted from /api/v1/csi/snapshot keyed
 * by csi_invoice_number. matched_order_invoice_id is set by the
 * reconciliation engine once the CSI invoice is matched to a Snipe
 * OrderInvoice; CSI is authoritative for the invoice amount.
 */
class CsiInvoice extends Model
{
    protected $table = 'csi_invoices';

    protected $fillable = [
        'csi_invoice_number',
        'lease_number',
        'schedule_name',
        'invoice_date',
        'amount',
        'currency',
        'matched_order_invoice_id',
        'raw',
        'last_seen_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'amount' => 'decimal:2',
        'raw' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function matchedOrderInvoice()
    {
        return $this->belongsTo(OrderInvoice::class, 'matched_order_invoice_id');
    }
}
