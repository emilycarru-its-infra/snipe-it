<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of one device's line on a CSI rental invoice (CSI's Invoice/Assets
 * endpoint). Upserted from /api/v1/csi/snapshot keyed by (csi_invoice_number,
 * csi_asset_id, serial). The invoice total lives on CsiInvoice; this is the
 * per-device rent and tax that adds up to it.
 */
class CsiInvoiceAsset extends Model
{
    protected $table = 'csi_invoice_assets';

    protected $fillable = [
        'csi_invoice_number',
        'csi_asset_id',
        'serial',
        'lease_number',
        'schedule_name',
        'period_start_date',
        'period_end_date',
        'rent',
        'period_rent',
        'tax_gst',
        'tax_pst',
        'tax_other',
        'tax_rate',
        'currency',
        'raw',
        'last_seen_at',
    ];

    protected $casts = [
        'period_start_date' => 'date',
        'period_end_date' => 'date',
        'rent' => 'decimal:2',
        'period_rent' => 'decimal:2',
        'tax_gst' => 'decimal:2',
        'tax_pst' => 'decimal:2',
        'tax_other' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'raw' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(CsiInvoice::class, 'csi_invoice_number', 'csi_invoice_number');
    }
}
