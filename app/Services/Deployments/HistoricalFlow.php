<?php

namespace App\Services\Deployments;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Reconstructed device flow for a PAST fiscal year, where no deployment
 * items were tracked. Every asset that moved that year gets ONE stage —
 * its furthest reconstructed point — so the rail counts and the device
 * table are the same list:
 *
 *   deployed     a checkout action_log in the FY
 *   inventoried  created_at in the FY (and not checked out that year)
 *   ordered      purchase_date in the FY (and neither of the above)
 *
 * Arrivals and provisioning were never time-stamped historically, so no
 * asset reconstructs to those stages.
 */
class HistoricalFlow
{
    /** @return array<string, \Illuminate\Support\Collection<int, Asset>> keyed by stage slug */
    public function assetsByStage(?string $fy): array
    {
        $range = RefreshForecast::fiscalYearRange($fy);
        if ($range === null) {
            return [];
        }

        [$start, $end] = $range;

        $deployedIds = DB::table('action_logs')
            ->where('item_type', Asset::class)
            ->where('action_type', 'checkout')
            ->whereBetween('created_at', [$start, $end])
            ->distinct()
            ->pluck('item_id')
            ->all();

        $inventoriedIds = Asset::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('id', $deployedIds ?: [-1])
            ->pluck('id')
            ->all();

        $orderedIds = Asset::query()
            ->whereBetween('purchase_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('id', array_merge($deployedIds ?: [], $inventoriedIds ?: []) ?: [-1])
            ->pluck('id')
            ->all();

        $load = fn (array $ids) => $ids
            ? Asset::query()->whereIn('id', $ids)->with(['model', 'status', 'location'])->orderBy('asset_tag')->get()
            : collect();

        return [
            'ordered' => $load($orderedIds),
            'inventoried' => $load($inventoriedIds),
            'deployed' => $load($deployedIds),
        ];
    }
}
