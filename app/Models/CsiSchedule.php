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

    /**
     * The schedules an order can be placed against today. Two are open at
     * any time — a four-year lease-to-return and a five-year lease-to-own —
     * and they roll over quarterly, so this is deliberately read live
     * rather than configured: the answer to "which lease is open" changes
     * without anyone editing a setting.
     *
     * A schedule with no end date counts as open. CSI issues the document
     * before the term is signed, and that is exactly the window in which
     * the newest schedule is the one to order against.
     *
     * @return array<int, string>
     */
    public static function openScheduleNames(): array
    {
        return static::query()
            ->where(fn ($q) => $q->whereNull('term_end_date')->orWhere('term_end_date', '>=', now()->toDateString()))
            ->orderByDesc('schedule_name')
            ->pluck('schedule_name')
            ->all();
    }
}
