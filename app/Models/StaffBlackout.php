<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use Watson\Validating\ValidatingTrait;

/**
 * A window when a deploy-team member is unavailable (vacation / OOO).
 * Used to schedule deploy windows around staff capacity and warn on
 * collisions. Mostly synced from M365 / Entra calendars (source='graph',
 * external_id = the Graph event id) by the deployment-staff-sync function;
 * source='manual' rows can be hand-added in the UI.
 */
class StaffBlackout extends SnipeModel
{
    use Loggable;
    use ValidatingTrait;

    protected $table = 'staff_blackouts';

    protected $rules = [
        'user_id' => 'required|exists:users,id',
        'source' => 'nullable|string|max:16',
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
        'reason' => 'nullable|string|max:191',
        'external_id' => 'nullable|string|max:191',
        'synced_at' => 'nullable|date',
    ];

    protected $fillable = [
        'user_id',
        'source',
        'start_date',
        'end_date',
        'reason',
        'external_id',
        'synced_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Blackouts overlapping a [start, end] window (for Gantt collision checks). */
    public function scopeOverlapping($query, $start, $end)
    {
        return $query->where('start_date', '<=', $end)->where('end_date', '>=', $start);
    }
}
