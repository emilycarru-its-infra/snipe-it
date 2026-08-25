<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CsiSchedule;
use App\Models\Requisition;
use Illuminate\Http\JsonResponse;

/**
 * The CSI lease schedules, and which pair is open for ordering.
 *
 * Worth reading together, because they disagree by design: the pair being
 * ordered against commences at the start of the next quarter, and CSI does
 * not publish a schedule until it commences — so the open pair is normally
 * absent from the mirror. Showing both makes that expected gap legible and
 * a genuinely stalled poller obvious.
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
            'open_pair_in_mirror' => [
                'return' => $known->contains('schedule_name', $pair['return']),
                'own' => $known->contains('schedule_name', $pair['own']),
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
