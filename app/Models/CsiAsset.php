<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of an asset accepted onto a CSI lease schedule. Upserted from
 * /api/v1/csi/snapshot keyed by (serial, schedule_name). Accepted CSI
 * assets carry the serial in the `Serial` field (in-process assets use
 * `SerialNumber` — see CsiInprocessAsset); the poller normalizes both to
 * `serial`.
 */
class CsiAsset extends Model
{
    protected $table = 'csi_assets';

    protected $fillable = [
        'serial',
        'lease_number',
        'schedule_name',
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
