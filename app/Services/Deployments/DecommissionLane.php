<?php

namespace App\Services\Deployments;

use App\Models\Asset;
use App\Models\Statuslabel;

/**
 * The reverse pipeline on the Deployments board: devices on their way OUT —
 * lease returns, donations, recycling. The incoming rail tracks boxes into
 * rooms; this lane tracks the same physical work in the other direction.
 *
 * The lane is derived from fields devices already carry, nothing new to
 * maintain:
 *
 *   collecting      status label in the Processing* family (created in the
 *                   running instance for exactly this — e.g. "Processing
 *                   (Return)"), no decommission date yet. The device is
 *                   being gathered, wiped, packed.
 *   decommissioned  decommission_date stamped — returned / donated /
 *                   recycled; it has left our management.
 *   archived        decommissioned AND parked on an archived status label,
 *                   the terminal resting state.
 */
class DecommissionLane
{
    /** Collecting-table rows shown before collapsing to a "+N more" line. */
    private const ROW_CAP = 20;

    public function build(?string $fy): array
    {
        $range = RefreshForecast::fiscalYearRange($fy);

        $processingStatuses = Statuslabel::where('name', 'like', 'Processing%')
            ->orderBy('name')
            ->get();

        $collecting = Asset::query()
            ->whereIn('status_id', $processingStatuses->pluck('id')->all() ?: [-1])
            ->whereNull('decommission_date')
            ->with(['model', 'status', 'location', 'lessor'])
            ->orderBy('lease_end_date')
            ->get();

        // Decommissioned is FY-scoped by the decommission date so the lane
        // reads as "this year's outgoing work", matching the board's FY
        // filter; without an FY it is the whole history.
        $decommissioned = Asset::query()
            ->whereNotNull('decommission_date')
            ->when($range, fn ($q) => $q->whereBetween('decommission_date', $range))
            ->with('status')
            ->get();

        $archivedCount = $decommissioned
            ->filter(fn ($asset) => (bool) $asset->status?->archived)
            ->count();

        $rows = $collecting->take(self::ROW_CAP)->map(fn ($asset) => [
            'id' => $asset->id,
            'asset_tag' => $asset->asset_tag,
            'model' => $asset->model?->name,
            'status' => $asset->status?->name,
            'location' => $asset->location?->name,
            'lessor' => $asset->lessor?->name,
            // Plain 'Y-m-d' string — the lease date columns are deliberately
            // not Carbon-cast (see Asset::$casts).
            'lease_end_date' => $asset->lease_end_date,
        ])->values()->all();

        // Where the outgoing devices physically sit — the holding rooms the
        // pickup truck (or the donation run) has to visit.
        $byLocation = $collecting
            ->groupBy(fn ($asset) => $asset->location?->name ?: '—')
            ->map(fn ($group, $name) => ['location' => $name, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'statuses' => $processingStatuses->map(fn ($status) => [
                'name' => $status->name,
                'count' => $collecting->where('status_id', $status->id)->count(),
            ])->values()->all(),
            'collectingCount' => $collecting->count(),
            'collectingRows' => $rows,
            'collectingMore' => max($collecting->count() - self::ROW_CAP, 0),
            'byLocation' => $byLocation,
            'decommissionedCount' => $decommissioned->count(),
            'archivedCount' => $archivedCount,
        ];
    }
}
