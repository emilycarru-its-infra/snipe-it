<?php

namespace App\Services\Deployments;

use App\Http\Controllers\ProcurementReportsController;
use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentWave;
use App\Services\FiscalYear;
use Illuminate\Support\Collection;

/**
 * The next few fiscal years side by side, one column each — the multi-year
 * view the per-FY planning page cannot give. Nothing here is a new rule:
 * each column is the per-FY forecast and the per-FY capital request, read
 * the same way those pages read them, so a number on the horizon always
 * matches the page its column links to.
 *
 * Devices due follow the decision chain: a device planned onto a wave
 * counts in that wave's year; one on no wave counts where the forecast puts
 * it (the earlier of End of Life and lease end, or a deferral's target).
 * RefreshForecast already leaves wave-tracked devices out, so the two
 * halves never overlap.
 */
class PlanningHorizon
{
    /** How many fiscal years the horizon spans, starting with next year. */
    public const YEARS = 4;

    /**
     * The fiscal years on the horizon: next FY onward, so in FY2026-27 it
     * reads FY2027-28 through FY2030-31.
     *
     * @return array<int, string>
     */
    public static function fiscalYears(?\DateTimeInterface $date = null): array
    {
        $next = FiscalYear::currentStartYear($date) + 1;

        return array_map(fn (int $sy) => FiscalYear::label($sy), range($next, $next + self::YEARS - 1));
    }

    /**
     * One column per fiscal year.
     *
     * @return array<int, array{fy: string, forecast_devices: int, wave_devices: int, devices: int, cost: float, envelope: float, gap: float, waves: Collection}>
     */
    public function columns(?array $fiscalYears = null): array
    {
        $forecast = new RefreshForecast;
        $capital = app(ProcurementReportsController::class);

        return array_map(function (string $fy) use ($forecast, $capital) {
            $candidates = $forecast->forFiscalYear($fy);
            $forecastCost = (float) $candidates->sum(
                fn (Asset $asset) => $asset->replacementCostEstimate() ?? (float) ($asset->purchase_cost ?? 0)
            );

            $waves = DeploymentWave::where('fiscal_year', $fy)->withCount('items')->ordered()->get();
            $waveItems = DeploymentItem::with(['model.refreshCatalogItem', 'replacesAsset.model.refreshCatalogItem'])
                ->whereIn('wave_id', $waves->pluck('id')->all() ?: [0])
                ->get();

            // A wave item is priced at its planned model's catalog price —
            // the wave is the plan — and falls back to the like-for-like
            // estimate of the device it replaces, then that device's cost.
            $waveCost = (float) $waveItems->sum(
                fn (DeploymentItem $item) => $item->model?->refreshCatalogItem?->effectiveCost()
                    ?? $item->replacesAsset?->replacementCostEstimate()
                    ?? (float) ($item->replacesAsset->purchase_cost ?? 0)
            );

            $summary = $capital->capitalSummary($fy);
            $cost = $forecastCost + $waveCost;

            return [
                'fy' => $fy,
                'forecast_devices' => $candidates->count(),
                'wave_devices' => $waveItems->count(),
                'devices' => $candidates->count() + $waveItems->count(),
                'cost' => $cost,
                // The contracted envelope: lease schedules ending in the
                // year, the capital request's own authority.
                'envelope' => (float) $summary['envelope'],
                'gap' => (float) $summary['envelope'] - $cost,
                'waves' => $waves,
            ];
        }, $fiscalYears ?? self::fiscalYears());
    }
}
