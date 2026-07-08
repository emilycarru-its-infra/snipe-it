<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of a CSI lease schedule (e.g. "301452-007"). Upserted from
 * /api/v1/csi/snapshot keyed by schedule_name. Carries CSI's signed term
 * dates, rent and tax for reconciliation against Snipe lease data.
 */
class CsiSchedule extends Model
{
    protected $table = 'csi_schedules';

    protected $fillable = [
        'csi_schedule_id',
        'schedule_name',
        'lease_number',
        'term_start_date',
        'term_end_date',
        'rent',
        'tax',
        'currency',
        'payment_frequency',
        'csi_last_updated',
        'raw',
        'last_seen_at',
    ];

    protected $casts = [
        'term_start_date' => 'date',
        'term_end_date' => 'date',
        'rent' => 'decimal:2',
        'tax' => 'decimal:2',
        'csi_last_updated' => 'datetime',
        'raw' => 'array',
        'last_seen_at' => 'datetime',
    ];
}
