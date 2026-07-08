<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of a CSI master lease (e.g. "301452"). Upserted from
 * /api/v1/csi/snapshot keyed by lease_number.
 */
class CsiLease extends Model
{
    protected $table = 'csi_leases';

    protected $fillable = [
        'lease_number',
        'term_start_date',
        'term_end_date',
        'raw',
        'last_seen_at',
    ];

    protected $casts = [
        'term_start_date' => 'date',
        'term_end_date' => 'date',
        'raw' => 'array',
        'last_seen_at' => 'datetime',
    ];
}
