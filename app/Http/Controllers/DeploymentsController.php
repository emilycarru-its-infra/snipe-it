<?php

namespace App\Http\Controllers;

use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\DeploymentWave;
use App\Models\Order;
use App\Services\Deployments\RefreshForecast;
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

        // FY options = forecast-derived FYs unioned with FYs already on waves.
        $waveFys = DeploymentWave::query()->whereNotNull('fiscal_year')->distinct()->pluck('fiscal_year')->all();
        $fiscalYears = collect($forecast->availableFiscalYears())->merge($waveFys)->unique()->values();
        $fiscalYears = $fiscalYears->sortDesc()->values()->all();

        $fy = RefreshForecast::normalizeFy($request->query('fiscal_year')) ?: ($fiscalYears[0] ?? null);
        $typeFilter = $request->query('deployment_type');
        $stageFilter = $request->query('stage');

        $wavesQuery = DeploymentWave::query()
            ->with(['type', 'owner', 'location'])
            ->withCount('items');
        if ($fy) {
            $wavesQuery->where('fiscal_year', $fy);
        }
        if ($typeFilter) {
            $wavesQuery->where('deployment_type_id', (int) $typeFilter);
        }
        $waves = $wavesQuery->ordered()->get();

        // Items in scope (the FY's waves), for the widgets.
        $waveIds = $waves->pluck('id')->all();
        $itemsQuery = DeploymentItem::query()
            ->with(['stage', 'wave.type', 'model'])
            ->whereIn('wave_id', $waveIds ?: [0]);
        if ($stageFilter) {
            $itemsQuery->where('stage_id', (int) $stageFilter);
        }
        $items = $itemsQuery->get();

        if ($request->query('format') === 'csv') {
            return $this->streamWavesCsv($waves, $fy);
        }

        return view('reports.deployments.index', [
            'waves' => $waves,
            'types' => $types,
            'stages' => $stages,
            'fiscalYears' => $fiscalYears ?: [$fy],
            'fy' => $fy,
            'typeFilter' => $typeFilter,
            'stageFilter' => $stageFilter,
            'widgets' => $this->buildWidgets($items, $stages, $types),
            'forecastCount' => $fy ? $forecast->forFiscalYear($fy)->count() : 0,
            'downloadUrl' => route('reports.deployments', ['fiscal_year' => $fy, 'deployment_type' => $typeFilter, 'stage' => $stageFilter, 'format' => 'csv']),
        ]);
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
            'items.stage', 'items.asset', 'items.replacesAsset', 'items.model',
            'items.assignedUser', 'items.assignedTech',
        ]);

        return view('deployment-waves.show', [
            'wave' => $deploymentWave,
            'stages' => DeploymentStage::active()->ordered()->get(),
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
            if (! $wave->save()) {
                return redirect()->back()->withInput()->withErrors($wave->getErrors());
            }
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

            $asset = \App\Models\Asset::find($assetId);
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
        $now = \Carbon\Carbon::now();
        $startYear = $now->month >= 4 ? $now->year : $now->year - 1;

        return sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);
    }
}
