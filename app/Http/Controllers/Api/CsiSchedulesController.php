<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CsiSchedule;
use App\Models\Requisition;
use Illuminate\Http\JsonResponse;

/**
 * Report the lessor mirror beside confirmed ordering windows. A calendar
 * projection is returned separately and never establishes that ordering is open.
 */
class CsiSchedulesController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        $known = CsiSchedule::orderByDesc('schedule_name')->get();
        $pair = CsiSchedule::openPair();

        return response()->json([
            'open_pair' => $pair,
            'projected_pair' => CsiSchedule::projectedPair(),
            'open_schedules' => CsiSchedule::orderingWindows(),
            'open_pair_in_mirror' => [
                'return' => $pair['return'] !== null && $known->contains('schedule_name', $pair['return']),
                'own' => $pair['own'] !== null && $known->contains('schedule_name', $pair['own']),
            ],
            'mirror_last_seen_at' => $known->max('last_seen_at')?->toDateTimeString(),
            'total' => $known->count(),
            'rows' => $known->map(fn (CsiSchedule $schedule) => [
                'schedule_name' => $schedule->schedule_name,
                'lease_number' => $schedule->lease_number,
                'term_start_date' => $schedule->term_start_date?->toDateString(),
                'term_end_date' => $schedule->term_end_date?->toDateString(),
                'rent' => $schedule->rent,
                'last_seen_at' => $schedule->last_seen_at?->toDateTimeString(),
            ])->all(),
        ]);
    }
}
