<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of a CSI in-process asset — ordered/shipped but not yet accepted
 * onto a schedule. Upserted from /api/v1/csi/snapshot keyed by (serial,
 * order_number). CSI returns these with the serial in `SerialNumber`; the
 * poller normalizes it to `serial` so it matches accepted assets and Snipe.
 */
class CsiInprocessAsset extends Model
{
    protected $table = 'csi_inprocess_assets';

    protected $fillable = [
        'serial',
        'lease_number',
        'schedule_name',
        'order_number',
        'po_number',
        'manufacturer',
        'model',
        'raw',
        'last_seen_at',
    ];

    protected $casts = [
        'raw' => 'array',
        'last_seen_at' => 'datetime',
    ];
}
