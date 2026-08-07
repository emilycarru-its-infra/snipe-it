<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\DeploymentWave;
use App\Models\Location;
use App\Models\Order;
use App\Models\Statuslabel;
use App\Services\Deployments\DecommissionLane;
use App\Services\Deployments\DeploymentTimeline;
use App\Services\Deployments\RefreshForecast;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Deployments planning workspace — the OPERATIONAL sibling of the
 * FINANCIAL /reports/procurement board. `report()` renders the FY-filtered
 * dashboard (donut+count widgets over the FY's deployment_items, by stage /
 * type / replacement model) plus the wave list; `forecast()` + `addFromForecast()`
 * drive the headline auto-collection (RefreshForecast). The rest is wave
 * CRUD and a per-wave board (`show`). Authorization reuses the Order policy,
 * mirroring the exhibit board.
 */
class DeploymentsController extends Controller
{
    /** Palette for the per-model widget (models are free-string, no catalog color). */
    private const MODEL_PALETTE = ['#2980b9', '#27ae60', '#8e44ad', '#d35400', '#16a085', '#c0392b', '#2c3e50', '#f39c12', '#7f8c8d', '#1abc9c'];

    /**
     * The /reports/deployments board: FY/type/stage filters, three
     * donut+count widgets, the waves table, and a forecast summary count.
     * Supports ?format=csv (waves export).
     */
    public function report(Request $request)
    {
        $this->authorize('view', Order::class);

        $forecast = new RefreshForecast;

        $types = DeploymentType::active()->ordered()->get();
        $stages = DeploymentStage::active()->ordered()->get();

        // FY options: a bounded planning window (three years either side of
        // the current FY) plus any FY that actually carries waves. Deriving
        // the list from every asset EOL/lease-end date offered stray far
        // future years (a single 2036 EOL date put FY2036-37 in the picker)
        // while the board can never have anything to show there.
        $currentStartYear = now()->month >= 4 ? now()->year : now()->year - 1;
        $currentFy = sprintf('FY%d-%02d', $currentStartYear, ($currentStartYear + 1) % 100);
        $window = collect(range($currentStartYear - 3, $currentStartYear + 3))
            ->map(fn ($y) => sprintf('FY%d-%02d', $y, ($y + 1) % 100));
        $waveFys = DeploymentWave::query()->whereNotNull('fiscal_year')->distinct()->pluck('fiscal_year')->all();
        $fiscalYears = $window->merge($waveFys)->unique()->sortDesc()->values()->all();

        // No explicit choice opens on the current FY — never the far end of
        // the planning window.
        $fy = RefreshForecast::normalizeFy($request->query('fiscal_year')) ?: $currentFy;
        $typeFilter = $request->query('deployment_type');
        $stageFilter = $request->query('stage');

        $wavesQuery = DeploymentWave::query()
            ->with(['type', 'owner', 'location'])
            ->withCount('items')
            ->where('fiscal_year', $fy);
        if ($typeFilter) {
            $wavesQuery->where('deployment_type_id', (int) $typeFilter);
        }
        $waves = $wavesQuery->ordered()->get();

        // Items in scope (the FY's waves), for the widgets.
        $waveIds = $waves->pluck('id')->all();
        $allItems = DeploymentItem::query()
            ->with(['stage', 'wave.type', 'model'])
            ->whereIn('wave_id', $waveIds ?: [0])
            ->get();
        $items = $stageFilter
            ? $allItems->where('stage_id', (int) $stageFilter)->values()
            : $allItems;

        if ($request->query('format') === 'csv') {
            return $this->streamWavesCsv($waves, $fy);
        }

        // The stage rail always shows the whole funnel — it IS the stage
        // filter (a chevron click narrows the widgets below), so its counts
        // never narrow with the selection.
        $stageRail = $stages->map(fn ($stage) => [
            'id' => $stage->id,
            'name' => $stage->name,
            'color' => $stage->color ?: '#bdc3c7',
            'is_terminal' => (bool) $stage->is_terminal,
            'count' => $allItems->where('stage_id', $stage->id)->count(),
        ])->values()->all();

        return view('reports.deployments.index', [
            'waves' => $waves,
            'types' => $types,
            'stages' => $stages,
            'fiscalYears' => $fiscalYears,
            'fy' => $fy,
            'typeFilter' => $typeFilter,
            'stageFilter' => $stageFilter,
            'stageRail' => $stageRail,
            'widgets' => $this->buildWidgets($items, $stages, $types),
            'timeline' => (new DeploymentTimeline)->build($waves),
            'decommission' => (new DecommissionLane)->build($fy),
            // The full look-ahead list, not just a count: picking a future FY
            // shows that whole year's expected lease-end / EOL devices right
            // on the board, so next year's rollout can be planned before a
            // single wave exists.
            'forecastAssets' => $forecast->forFiscalYear($fy),
            'legacyFleet' => $this->legacyFleet(),
            'downloadUrl' => route('reports.deployments', ['fiscal_year' => $fy, 'deployment_type' => $typeFilter, 'stage' => $stageFilter, 'format' => 'csv']),
        ]);
    }

    /**
     * The unfunded aging fleet: devices parked on the 'Active (Legacy)'
     * status — still in daily use, but with no planned replacement and no
     * funding in sight. Leases carry their own pre-approved replacement
     * money from signing; these devices have none, which is exactly what
     * an exec reading this board needs to see next to the funded waves.
     */
    private function legacyFleet(): array
    {
        $statusIds = Statuslabel::where('name', 'like', 'Active (Legacy)%')->pluck('id');
        if ($statusIds->isEmpty()) {
            return ['count' => 0];
        }

        $assets = Asset::whereIn('status_id', $statusIds->all())
            ->with('model')
            ->get();

        $ages = $assets
            ->filter(fn ($asset) => $asset->purchase_date)
            ->map(fn ($asset) => $asset->purchase_date->diffInYears(now()));

        $byModel = $assets
            ->groupBy(fn ($asset) => $asset->model?->name ?: trans('general.na'))
            ->map->count()
            ->sortDesc()
            ->take(6);

        return [
            'count' => $assets->count(),
            'avg_age_years' => $ages->isEmpty() ? null : round($ages->avg(), 1),
            'oldest_year' => $assets->min(fn ($asset) => $asset->purchase_date?->format('Y')),
            'by_model' => $byModel->map(fn ($count, $name) => ['model' => $name, 'count' => $count])->values()->all(),
            'status_ids' => $statusIds->all(),
        ];
    }

    /**
     * Build the three widgets (by stage, by wave type, by replacement
     * model). Each returns count rows [label,count,pct,color] (zero rows
     * kept for the catalog dimensions) plus a non-zero-only `chart` array.
     */
    private function buildWidgets($items, $stages, $types): array
    {
        $total = max($items->count(), 1);
        $row = fn ($label, $count, $color) => [
            'label' => $label,
            'count' => $count,
            'pct' => round($count / $total * 100),
            'color' => $color ?: '#bdc3c7',
        ];

        $stageRows = [];
        foreach ($stages as $s) {
            $stageRows[] = $row($s->name, $items->where('stage_id', $s->id)->count(), $s->color);
        }

        $typeRows = [];
        foreach ($types as $t) {
            $count = $items->filter(fn ($i) => $i->wave && $i->wave->deployment_type_id == $t->id)->count();
            $typeRows[] = $row($t->name, $count, $t->color);
        }

        // Replacement-model buckets (top 10 by count), free-string labels.
        $modelRows = [];
        $byModel = $items->filter(fn ($i) => $i->model)->groupBy(fn ($i) => $i->model->name);
        foreach ($byModel as $name => $group) {
            $modelRows[] = $row($name, $group->count(), self::MODEL_PALETTE[count($modelRows) % count(self::MODEL_PALETTE)]);
        }
        usort($modelRows, fn ($a, $b) => $b['count'] <=> $a['count']);
        $modelRows = array_slice($modelRows, 0, 10);

        $chart = function (array $rows) {
            $nonzero = array_values(array_filter($rows, fn ($r) => $r['count'] > 0));

            return [
                'labels' => array_column($nonzero, 'label'),
                'data' => array_column($nonzero, 'count'),
                'colors' => array_column($nonzero, 'color'),
            ];
        };

        return [
            'stage' => ['rows' => $stageRows, 'chart' => $chart($stageRows)],
            'type' => ['rows' => $typeRows, 'chart' => $chart($typeRows)],
            'model' => ['rows' => $modelRows, 'chart' => $chart($modelRows)],
            'total' => $items->count(),
        ];
    }

    private function streamWavesCsv($waves, ?string $fy): StreamedResponse
    {
        $filename = 'deployments-'.($fy ? strtolower($fy) : 'all').'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->stream(function () use ($waves) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Wave', 'Type', 'State', 'Fiscal Year', 'Devices', 'Arrival Start', 'Arrival End', 'Deploy Start', 'Deploy End', 'Owner']);
            foreach ($waves as $w) {
                fputcsv($out, [
                    $w->name,
                    $w->typeLabel(),
                    $w->wave_state,
                    $w->fiscal_year,
                    $w->items_count,
                    optional($w->arrival_window_start)->toDateString(),
                    optional($w->arrival_window_end)->toDateString(),
                    optional($w->target_start_date)->toDateString(),
                    optional($w->target_end_date)->toDateString(),
                    $w->owner?->full_name ?? '',
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }

    /*
    |--------------------------------------------------------------------------
    | Storage / staging capacity (P3)
    |--------------------------------------------------------------------------
    */

    /**
     * The storage view: every Location with a storage_capacity, its current
     * staged-device count (deployment_items pointing at it that aren't yet
     * deployed), a fill bar, the device list, and any waves staging there.
     * Plus an "Unassigned" bucket for staged devices with no storage location.
     */
    public function storage(Request $request)
    {
        $this->authorize('view', Order::class);

        $locations = Location::query()
            ->whereNotNull('storage_capacity')
            ->orderBy('name')
            ->get();

        // All not-yet-deployed items, grouped by storage_location_id. "Staged"
        // = deployed_at is null AND the stage isn't terminal (a missing stage
        // row counts as not-yet-deployed too).
        $stagedItems = DeploymentItem::query()
            ->with(['stage', 'wave', 'asset', 'model'])
            ->whereNull('deployed_at')
            ->where(function ($q) {
                $q->whereNull('stage_id')
                    ->orWhereHas('stage', fn ($s) => $s->where('is_terminal', false));
            })
            ->get();

        $byLocation = $stagedItems->groupBy('storage_location_id');

        $rows = [];
        foreach ($locations as $location) {
            $items = $byLocation->get($location->id, collect());
            $rows[] = $this->storageRow($location->name, (int) $location->storage_capacity, $items, $location);
        }

        // Waves staging at each location (for the "waves staging here" line).
        $wavesByStorage = DeploymentWave::query()
            ->whereNotNull('storage_location_id')
            ->ordered()
            ->get()
            ->groupBy('storage_location_id');

        // Unassigned: staged items with no storage location.
        $unassignedItems = $byLocation->get(null, collect());
        $unassigned = $this->storageRow(
            trans('admin/deployments/general.storage_unassigned'),
            null,
            $unassignedItems,
            null,
        );

        return view('reports.deployments.storage', [
            'rows' => $rows,
            'wavesByStorage' => $wavesByStorage,
            'unassigned' => $unassigned,
            'unassignedCount' => $unassignedItems->count(),
        ]);
    }

    /** Build one storage row: capacity, staged count, fill %, bar tone, items. */
    private function storageRow(string $name, ?int $capacity, $items, ?Location $location): array
    {
        $count = $items->count();
        $pct = ($capacity && $capacity > 0) ? min(100, (int) round($count / $capacity * 100)) : 0;

        if (! $capacity) {
            $tone = 'progress-bar-aqua';
        } elseif ($count > $capacity) {
            $tone = 'progress-bar-danger';
        } elseif ($pct >= 85) {
            $tone = 'progress-bar-yellow';
        } else {
            $tone = 'progress-bar-green';
        }

        return [
            'location' => $location,
            'name' => $name,
            'capacity' => $capacity,
            'count' => $count,
            'pct' => $pct,
            'tone' => $tone,
            'over' => $capacity ? max(0, $count - $capacity) : 0,
            'items' => $items,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Wave CRUD
    |--------------------------------------------------------------------------
    */

    public function create()
    {
        $this->authorize('create', Order::class);

        return view('deployment-waves.create', [
            'wave' => new DeploymentWave([
                'wave_state' => 'planned',
                'deployment_type_id' => DeploymentType::where('slug', 'refresh')->value('id'),
                'fiscal_year' => $this->defaultFiscalYear(),
            ]),
            'types' => DeploymentType::active()->ordered()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $wave = new DeploymentWave;
        $wave->fill($request->all());
        $wave->created_by = auth()->id();

        if (! $wave->save()) {
            return redirect()->back()->withInput()->withErrors($wave->getErrors());
        }

        return redirect()->route('deployment-waves.show', $wave)
            ->with('success', trans('admin/deployments/general.created'));
    }

    public function show(DeploymentWave $deploymentWave)
    {
        $this->authorize('view', Order::class);

        $deploymentWave->load([
            'type', 'owner', 'location', 'storageLocation', 'purchaseOrder',
            'items.stage', 'items.asset', 'items.replacesAsset', 'items.model.refreshCatalogItem',
            'items.assignedUser', 'items.assignedTech',
            'items.orderItem.order', 'items.orderItem.shipment',
        ]);

        $timeline = new DeploymentTimeline;

        // Projected replacement spend: the comparable current model's live
        // catalog price per item, falling back to the replaced device's
        // purchase cost when the model has no refresh mapping.
        $projected = $deploymentWave->items->map(function ($item) {
            $catalog = $item->model?->refreshCatalogItem;

            return $catalog?->effectiveCost() ?? (float) ($item->replacesAsset->purchase_cost ?? 0);
        });

        return view('deployment-waves.show', [
            'wave' => $deploymentWave,
            'projectedTotal' => (float) $projected->sum(),
            'stages' => DeploymentStage::active()->ordered()->get(),
            'arrivals' => $timeline->arrivals($deploymentWave),
            'timeline' => $timeline,
        ]);
    }

    public function edit(DeploymentWave $deploymentWave)
    {
        $this->authorize('update', Order::class);

        return view('deployment-waves.edit', [
            'wave' => $deploymentWave,
            'types' => DeploymentType::active()->ordered()->get(),
        ]);
    }

    public function update(Request $request, DeploymentWave $deploymentWave): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $deploymentWave->fill($request->all());

        if (! $deploymentWave->save()) {
            return redirect()->back()->withInput()->withErrors($deploymentWave->getErrors());
        }

        return redirect()->route('deployment-waves.show', $deploymentWave)
            ->with('success', trans('admin/deployments/general.updated'));
    }

    public function destroy(DeploymentWave $deploymentWave): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $fy = $deploymentWave->fiscal_year;
        $deploymentWave->delete();

        return redirect()->route('reports.deployments', ['fiscal_year' => $fy])
            ->with('success', trans('admin/deployments/general.deleted'));
    }

    /** CSV of a single wave's items. */
    public function exportWave(DeploymentWave $deploymentWave): StreamedResponse
    {
        $this->authorize('view', Order::class);

        $deploymentWave->load(['items.stage', 'items.asset', 'items.replacesAsset', 'items.model', 'items.assignedUser', 'items.assignedTech', 'items.storageLocation']);

        $filename = 'wave-'.($deploymentWave->slug ?: $deploymentWave->id).'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->stream(function () use ($deploymentWave) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Stage', 'Device', 'Replaces', 'Model', 'Recipient', 'Tech', 'Target Deploy', 'Storage', 'Notes']);
            foreach ($deploymentWave->items as $item) {
                fputcsv($out, [
                    $item->stageLabel(),
                    $item->deviceLabel(),
                    $item->replacesAsset ? ($item->replacesAsset->asset_tag ?: $item->replacesAsset->name) : '',
                    $item->model?->name ?? '',
                    $item->assignedUser?->full_name ?? '',
                    $item->assignedTech?->full_name ?? '',
                    optional($item->target_deploy_date)->toDateString(),
                    $item->storageLocation?->name ?? '',
                    $item->notes,
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }

    /*
    |--------------------------------------------------------------------------
    | Forecast (auto-collect lease-ends / EOL)
    |--------------------------------------------------------------------------
    */

    public function forecast(Request $request)
    {
        $this->authorize('view', Order::class);

        $forecast = new RefreshForecast;
        $fiscalYears = $forecast->availableFiscalYears();
        $fy = RefreshForecast::normalizeFy($request->query('fiscal_year')) ?: ($fiscalYears[0] ?? null);

        $candidates = $fy ? $forecast->forFiscalYear($fy) : collect();

        $waves = $fy
            ? DeploymentWave::where('fiscal_year', $fy)->ordered()->get()
            : DeploymentWave::ordered()->get();

        return view('reports.deployments.forecast', [
            'candidates' => $candidates,
            'fiscalYears' => $fiscalYears ?: [$fy],
            'fy' => $fy,
            'waves' => $waves,
            'leaseColumnPresent' => RefreshForecast::leaseEndColumn() !== null,
        ]);
    }

    /**
     * Bulk-add checked forecast assets to a wave as replacement items. If a
     * new wave name is given, create a Refresh wave for the FY first.
     * Idempotent: assets already on an item are skipped.
     */
    public function addFromForecast(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer',
            'fiscal_year' => 'nullable|string',
        ]);

        $fy = RefreshForecast::normalizeFy($request->input('fiscal_year'));
        $waveId = $request->input('wave_id');
        $newWaveName = trim((string) $request->input('new_wave_name'));

        if ($waveId) {
            $wave = DeploymentWave::findOrFail((int) $waveId);
        } elseif ($newWaveName !== '') {
            $wave = new DeploymentWave([
                'name' => $newWaveName,
                'fiscal_year' => $fy,
                'wave_state' => 'planned',
                'deployment_type_id' => DeploymentType::where('slug', 'refresh')->value('id'),
            ]);
            $wave->created_by = auth()->id();
            $wave->save();
        } else {
            return redirect()->back()->withInput()
                ->with('error', trans('admin/deployments/general.forecast_no_wave'));
        }

        $plannedStageId = DeploymentStage::where('slug', 'planned')->value('id');

        // Skip assets already tracked by any deployment item.
        $tracked = DeploymentItem::query()
            ->whereIn('replaces_asset_id', $request->input('asset_ids'))
            ->pluck('replaces_asset_id')
            ->merge(DeploymentItem::query()->whereIn('asset_id', $request->input('asset_ids'))->pluck('asset_id'))
            ->unique()
            ->all();

        $added = 0;
        foreach ($request->input('asset_ids') as $assetId) {
            $assetId = (int) $assetId;
            if (in_array($assetId, $tracked, true)) {
                continue;
            }

            $asset = Asset::find($assetId);
            if (! $asset) {
                continue;
            }

            $item = new DeploymentItem([
                'wave_id' => $wave->id,
                'replaces_asset_id' => $asset->id,
                'model_id' => $asset->model_id,
                'stage_id' => $plannedStageId,
            ]);
            if ($item->save()) {
                $added++;
            }
        }

        return redirect()->route('deployment-waves.show', $wave)
            ->with('success', trans('admin/deployments/general.forecast_added', ['count' => $added]));
    }

    /** Default FY label for a new wave (current ECU fiscal year). */
    private function defaultFiscalYear(): string
    {
        $now = Carbon::now();
        $startYear = $now->month >= 4 ? $now->year : $now->year - 1;

        return sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);
    }
}
