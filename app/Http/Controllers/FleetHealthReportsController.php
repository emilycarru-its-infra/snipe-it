<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\AssetModel;
use App\Models\Statuslabel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fleet Health dashboard — answers "what's our fleet actually look like
 * right now?" without the procurement / contracts financial lens. Sits
 * alongside the other tile dashboards at /reports.
 *
 * All charts are derived live from the assets / asset_maintenances /
 * statuslabels tables. No caching: the dataset is small enough
 * (~thousands of rows) that a single page render is well under a
 * second on prod.
 */
class FleetHealthReportsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('reports.fleet-health.view');

        $cards         = $this->headlineCards();
        $statusDonut   = $this->statusDonut();
        $topModels     = $this->topModels(15);
        $ageHistogram  = $this->ageHistogram();
        $topRepairs    = $this->topRepairModels(10);
        $auditOverdue  = $this->unauditedCount(months: 12);

        return view('reports.fleet-health', [
            'cards'         => $cards,
            'statusDonut'   => $statusDonut,
            'topModels'     => $topModels,
            'ageHistogram'  => $ageHistogram,
            'topRepairs'    => $topRepairs,
            'auditOverdue'  => $auditOverdue,
        ]);
    }

    /**
     * Four KPI tiles across the top — quick situational read.
     */
    private function headlineCards(): array
    {
        $assetsTotal     = Asset::count();
        $assetsDeployed  = Asset::whereHas('status', fn ($q) => $q->where('deployable', 1))
            ->whereNotNull('assigned_to')
            ->count();
        $modelsActive    = AssetModel::has('assets')->count();
        $repairsThisYear = AssetMaintenance::whereYear('created_at', now()->year)->count();

        return [
            ['label' => trans('admin/reports/general.fleet_card_assets_total'), 'value' => $assetsTotal, 'tone' => 'aqua', 'icon' => 'fa-barcode'],
            ['label' => trans('admin/reports/general.fleet_card_assets_deployed'), 'value' => $assetsDeployed, 'tone' => 'green', 'icon' => 'fa-user-check'],
            ['label' => trans('admin/reports/general.fleet_card_models_active'), 'value' => $modelsActive, 'tone' => 'blue', 'icon' => 'fa-cubes'],
            ['label' => trans('admin/reports/general.fleet_card_repairs_ytd'), 'value' => $repairsThisYear, 'tone' => 'yellow', 'icon' => 'fa-wrench'],
        ];
    }

    /**
     * Counts of assets per Snipe statuslabel type (deployable, pending,
     * undeployable, archived). The type bucket is computed in PHP
     * because Snipe stores it as a derived value, not a column.
     */
    private function statusDonut(): array
    {
        $buckets = [
            'deployable'   => ['label' => trans('admin/reports/general.fleet_status_deployable'), 'count' => 0, 'color' => '#00a65a'],
            'pending'      => ['label' => trans('admin/reports/general.fleet_status_pending'),    'count' => 0, 'color' => '#f39c12'],
            'undeployable' => ['label' => trans('admin/reports/general.fleet_status_undeployable'),'count' => 0, 'color' => '#dd4b39'],
            'archived'     => ['label' => trans('admin/reports/general.fleet_status_archived'),   'count' => 0, 'color' => '#6c757d'],
        ];

        $rows = DB::table('assets')
            ->join('status_labels', 'assets.status_id', '=', 'status_labels.id')
            ->whereNull('assets.deleted_at')
            ->select('status_labels.deployable', 'status_labels.pending', 'status_labels.archived', DB::raw('COUNT(*) as n'))
            ->groupBy('status_labels.deployable', 'status_labels.pending', 'status_labels.archived')
            ->get();

        foreach ($rows as $row) {
            $key = $this->statusBucket((int) $row->deployable, (int) $row->pending, (int) $row->archived);
            $buckets[$key]['count'] += (int) $row->n;
        }

        return array_values($buckets);
    }

    private function statusBucket(int $deployable, int $pending, int $archived): string
    {
        if ($archived === 1)  return 'archived';
        if ($pending === 1)   return 'pending';
        if ($deployable === 1) return 'deployable';
        return 'undeployable';
    }

    /**
     * Top N models by asset count — answers "which models do we actually
     * run?", which is rarely the same as "which models do we buy?".
     */
    private function topModels(int $limit): array
    {
        $rows = DB::table('assets')
            ->join('models', 'assets.model_id', '=', 'models.id')
            ->whereNull('assets.deleted_at')
            ->whereNull('models.deleted_at')
            ->select('models.id', 'models.name', DB::raw('COUNT(*) as n'))
            ->groupBy('models.id', 'models.name')
            ->orderByDesc('n')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => ['label' => $r->name, 'count' => (int) $r->n])->all();
    }

    /**
     * Age buckets in years-since-purchase_date. Hard-coded buckets so
     * the legend stays human-readable.
     */
    private function ageHistogram(): array
    {
        $today = Carbon::now()->startOfDay();
        $buckets = [
            '<1y'   => ['label' => trans('admin/reports/general.fleet_age_lt_1y'),  'count' => 0, 'min' => 0, 'max' => 1],
            '1-2y'  => ['label' => trans('admin/reports/general.fleet_age_1_2y'),   'count' => 0, 'min' => 1, 'max' => 2],
            '2-3y'  => ['label' => trans('admin/reports/general.fleet_age_2_3y'),   'count' => 0, 'min' => 2, 'max' => 3],
            '3-5y'  => ['label' => trans('admin/reports/general.fleet_age_3_5y'),   'count' => 0, 'min' => 3, 'max' => 5],
            '5y+'   => ['label' => trans('admin/reports/general.fleet_age_gt_5y'),  'count' => 0, 'min' => 5, 'max' => 999],
        ];

        Asset::query()
            ->whereNotNull('purchase_date')
            ->select('purchase_date')
            ->chunk(2000, function ($chunk) use (&$buckets, $today) {
                foreach ($chunk as $asset) {
                    if (! $asset->purchase_date) continue;
                    $ageYears = Carbon::parse($asset->purchase_date)->floatDiffInYears($today);
                    foreach ($buckets as $key => $b) {
                        if ($ageYears >= $b['min'] && $ageYears < $b['max']) {
                            $buckets[$key]['count']++;
                            break;
                        }
                    }
                }
            });

        return array_values(array_map(fn ($b) => ['label' => $b['label'], 'count' => $b['count']], $buckets));
    }

    /**
     * Top N models by maintenance count — the "which laptop breaks the
     * most?" view that informs the next bulk order.
     */
    private function topRepairModels(int $limit): array
    {
        $rows = DB::table('asset_maintenances')
            ->join('assets', 'asset_maintenances.asset_id', '=', 'assets.id')
            ->join('models', 'assets.model_id', '=', 'models.id')
            ->whereNull('asset_maintenances.deleted_at')
            ->whereNull('assets.deleted_at')
            ->whereNull('models.deleted_at')
            ->select('models.name', DB::raw('COUNT(*) as repairs'))
            ->groupBy('models.id', 'models.name')
            ->orderByDesc('repairs')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => ['label' => $r->name, 'count' => (int) $r->repairs])->all();
    }

    /**
     * Count of active assets that haven't been audited within the last N
     * months (or have never been audited at all). Surfaces the
     * data-quality tail nobody otherwise sees.
     */
    private function unauditedCount(int $months): int
    {
        $cutoff = now()->subMonths($months);

        return Asset::query()
            ->whereHas('status', fn ($q) => $q->where('deployable', 1))
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_audit_date')
                  ->orWhere('last_audit_date', '<', $cutoff);
            })
            ->count();
    }
}
