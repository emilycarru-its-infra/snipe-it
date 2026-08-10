<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Statuslabel;

/**
 * The unfunded aging fleet: devices parked on 'Active (Legacy)' and
 * 'Active (Buyout*)' statuses — still in daily use, past their funded
 * life. Leases carry pre-approved replacement money from signing; these
 * devices have none, which is exactly what an exec reading the
 * procurement board needs to see next to the funded pipeline. Shared by
 * /reports/procurement and /reports/fleet-health.
 */
class LegacyFleet
{
    public static function summary(): array
    {
        $sections = [];
        $total = 0;
        $totalCost = 0.0;

        foreach ([
            ['key' => 'legacy', 'pattern' => 'Active (Legacy)%'],
            ['key' => 'buyout', 'pattern' => 'Active (Buyout%'],
        ] as $definition) {
            $statuses = Statuslabel::where('name', 'like', $definition['pattern'])->get();
            if ($statuses->isEmpty()) {
                continue;
            }

            $assets = Asset::whereIn('status_id', $statuses->pluck('id')->all())
                ->with('model')
                ->get();
            if ($assets->isEmpty()) {
                continue;
            }

            $ages = $assets
                ->filter(fn ($asset) => $asset->purchase_date)
                ->map(fn ($asset) => $asset->purchase_date->diffInYears(now()));

            $byModel = $assets
                ->groupBy(fn ($asset) => $asset->model?->name ?: trans('general.na'))
                ->map(fn ($group, $name) => [
                    'model' => $name,
                    'model_id' => $group->first()->model_id,
                    'count' => $group->count(),
                ])
                ->sortByDesc('count')
                ->values();

            $sectionCost = (float) $assets->sum(fn ($asset) => (float) $asset->purchase_cost);
            $total += $assets->count();
            $totalCost += $sectionCost;
            $sections[] = [
                'key' => $definition['key'],
                'label' => $statuses->count() === 1 ? $statuses->first()->name : rtrim($definition['pattern'], '%'),
                'count' => $assets->count(),
                'purchase_cost' => $sectionCost,
                'avg_age_years' => $ages->isEmpty() ? null : round($ages->avg(), 1),
                'oldest_year' => $assets->min(fn ($asset) => $asset->purchase_date?->format('Y')),
                'by_model' => $byModel->all(),
                'status_ids' => $statuses->pluck('id')->all(),
            ];
        }

        return ['count' => $total, 'purchase_cost' => $totalCost, 'sections' => $sections];
    }
}
