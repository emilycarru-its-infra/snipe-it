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
use App\Services\Deployments\HistoricalFlow;
use App\Services\Deployments\RefreshForecast;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use App\Services\Deployments\WaveAnnouncer;
use App\Services\Deployments\WaveMembership;
use App\Services\Deployments\WaveAnnouncementTemplates;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Deployments planning workspace — the OPERATIONAL sibling of the
 * FINANCIAL /reports/procurement board. `report()` renders the FY-filtered
 * board: the Device Flow rail over one unified device table (wave items +
 * the FY's refresh backlog), the timeline, the wave list and the
 * decommissioning lane; `forecast()` + `addFromForecast()` drive the
 * headline auto-collection (RefreshForecast). The rest is wave CRUD and a
 * per-wave board (`show`). Gated by the deployments module permission:
 * read for every board page, read+write for anything that changes state.
 */
class DeploymentsController extends Controller
{
    /**
     * The /reports/deployments board. Supports ?format=csv (waves export)
     * and ?format=csv&decom_pickup=Y-m-d (one pickup run's device list).
     */
    public function report(Request $request)
    {
        $this->authorize('deployments.view');

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
        // Oldest first — reading order matches the passage of time.
        $fiscalYears = $window->merge($waveFys)->unique()->sort()->values()->all();

        // No explicit choice opens on the current FY — never the far end of
        // the planning window.
        $fy = RefreshForecast::normalizeFy($request->query('fiscal_year')) ?: $currentFy;
        $typeFilter = $request->query('deployment_type');
        $fyStartYear = RefreshForecast::fiscalYearStartYear($fy);
        $isPast = $fyStartYear < $currentStartYear;
        $isFuture = $fyStartYear > $currentStartYear;

        if ($request->query('format') === 'csv' && $request->filled('decom_pickup')) {
            return $this->streamPickupCsv((string) $request->query('decom_pickup'));
        }

        $wavesQuery = DeploymentWave::query()
            ->with(['type', 'owner', 'location'])
            ->withCount('items')
            ->where('fiscal_year', $fy);
        if ($typeFilter) {
            $wavesQuery->where('deployment_type_id', (int) $typeFilter);
        }
        $waves = $wavesQuery->ordered()->get();

        // Every tracked device on the FY's waves.
        $waveIds = $waves->pluck('id')->all();
        $allItems = DeploymentItem::query()
            ->with(['stage', 'wave.type', 'model', 'asset.location', 'asset.status', 'asset.model', 'replacesAsset.location', 'replacesAsset.status', 'replacesAsset.model'])
            ->whereIn('wave_id', $waveIds ?: [0])
            ->get();

        if ($request->query('format') === 'csv') {
            return $this->streamWavesCsv($waves, $fy);
        }

        // One row per device, whatever the source lens: tracked wave items,
        // the refresh backlog (due this FY, on no wave — for a past FY that
        // means never refreshed, so the plan is still open), the FY's
        // procurement order lines not yet tracked (ordered → arrived →
        // deployed straight from the money side), and — for past years —
        // the reconstruction from asset history, each device at its
        // furthest stage. The rail counts THESE rows, so the chevrons and
        // the table can never disagree.
        $deviceRows = $this->deviceRows($allItems, $forecast->forFiscalYear($fy));
        if ($isPast) {
            $deviceRows = array_merge($deviceRows, $this->historicalRows($fy, $allItems));
        } else {
            $deviceRows = array_merge($deviceRows, $this->orderRows($fy, $allItems), $this->requisitionRows($fy));
        }

        $rowCounts = collect($deviceRows)->countBy('stage_slug');
        $stageRail = $stages->map(fn ($stage) => [
            'id' => $stage->id,
            'slug' => $stage->slug,
            'name' => $stage->name,
            'color' => $stage->color ?: '#bdc3c7',
            'is_terminal' => (bool) $stage->is_terminal,
            'count' => (int) $rowCounts->get($stage->slug, 0),
        ])->values()->all();

        return view('reports.deployments.index', [
            'waves' => $waves,
            'types' => $types,
            'stages' => $stages,
            'fiscalYears' => $fiscalYears,
            'fy' => $fy,
            'isPast' => $isPast,
            'isFuture' => $isFuture,
            'typeFilter' => $typeFilter,
            'stageRail' => $stageRail,
            'deviceRows' => $deviceRows,
            'backlogCount' => collect($deviceRows)->where('kind', 'backlog')->count(),
            'timeline' => (new DeploymentTimeline)->build($waves),
            'decommission' => $isFuture ? null : (new DecommissionLane)->build($fy, ! $isPast),
            'downloadUrl' => route('reports.deployments', ['fiscal_year' => $fy, 'deployment_type' => $typeFilter, 'format' => 'csv']),
        ]);
    }

    /**
     * One row per device in the FY's flow — wave items first, then the
     * refresh backlog (due this FY, on no wave yet) as Planned rows. The
     * asset is the unit of measurement throughout; a backlog row carries
     * asset_id (for add-to-wave), an item row carries item_id (for stage
     * moves) plus has_order (whether the procurement gate is satisfied).
     */
    private function deviceRows($allItems, $forecastAssets): array
    {
        $reasonLabel = [
            'eol' => trans('admin/deployments/general.reason_eol'),
            'lease' => trans('admin/deployments/general.reason_lease'),
            'both' => trans('admin/deployments/general.reason_both'),
        ];

        $rows = [];
        foreach ($allItems as $item) {
            $subject = $item->asset ?: $item->replacesAsset;
            // The device column names the physical unit, never the model:
            // the incoming asset once it exists, otherwise the device being
            // replaced (that machine IS the unit until its successor lands).
            $device = $item->asset?->name ?: $item->asset?->asset_tag
                ?: $item->replacesAsset?->name ?: $item->replacesAsset?->asset_tag
                ?: '—';
            $rows[] = [
                'kind' => 'item',
                'item_id' => $item->id,
                'asset_id' => null,
                'device' => $device,
                'device_url' => $subject ? route('hardware.show', $subject->id) : null,
                'model' => $item->model?->name ?: $subject?->model?->name ?: '—',
                'wave' => $item->wave?->name,
                'wave_url' => $item->wave ? route('deployment-waves.show', $item->wave) : null,
                'wave_color' => $item->wave?->displayColor(),
                'type' => $item->wave?->typeLabel() ?: '—',
                'group' => $item->group_label,
                'context' => $item->replacesAsset
                    ? trans('admin/deployments/general.replaces').' '.($item->replacesAsset->asset_tag ?: $item->replacesAsset->name)
                    : '—',
                'due' => optional($item->target_deploy_date)->toDateString() ?: '—',
                'status' => $subject?->status?->name ?: '—',
                'location' => $subject?->location?->name ?: $item->wave?->location?->name ?: '—',
                'stage_slug' => $item->stage?->slug ?: 'planned',
                'stage_name' => $item->stageLabel(),
                'stage_color' => $item->stageColor(),
                'has_order' => (bool) $item->order_item_id,
            ];
        }

        foreach ($forecastAssets as $asset) {
            $rows[] = [
                'kind' => 'backlog',
                'item_id' => null,
                'asset_id' => $asset->id,
                'device' => $asset->name ?: $asset->asset_tag ?: ('#'.$asset->id),
                'device_url' => route('hardware.show', $asset->id),
                'model' => $asset->model?->name ?: '—',
                'wave' => null,
                'wave_url' => null,
                'wave_color' => null,
                'type' => trans('admin/deployments/general.flow_backlog_chip'),
                'group' => null,
                'context' => ($reasonLabel[$asset->refresh_reason] ?? $asset->refresh_reason)
                    .($asset->lease_decision_label ? ' · '.$asset->lease_decision_label : ''),
                'due' => $asset->source_date ?: '—',
                'status' => $asset->status?->name ?: '—',
                'location' => $asset->location?->name ?: '—',
                'stage_slug' => 'planned',
                'stage_name' => trans('admin/deployments/general.flow_backlog_stage'),
                'stage_color' => '#7f8c8d',
                'has_order' => false,
            ];
        }

        return $rows;
    }

    /**
     * The FY's procurement order lines, straight from the money side —
     * every actual order's line items that aren't already tracked as a
     * deployment item. An unreceived line is Ordered; a received line is
     * Arrived; a received asset in someone's hands is Deployed. This is
     * what keeps the two boards looking at the same physical devices.
     */
    private function orderRows(?string $fy, $allItems): array
    {
        $linkedOrderItemIds = $allItems->pluck('order_item_id')->filter()->all();

        $orders = Order::actual()
            ->when($fy, fn ($q) => $q->where('fiscal_year', $fy))
            ->whereIn('status', ['ordered', 'shipped', 'partially_received', 'received'])
            ->with(['items.item.model', 'items.item.location', 'items.item.status', 'supplier', 'purchaseOrder'])
            ->orderBy('order_date')
            ->get();

        $stageMeta = [
            'ordered' => ['name' => trans('admin/deployments/general.flow_order_stage_ordered'), 'color' => '#2980b9'],
            'arrived' => ['name' => trans('admin/deployments/general.flow_order_stage_arrived'), 'color' => '#27ae60'],
            'deployed' => ['name' => trans('admin/deployments/general.flow_order_stage_deployed'), 'color' => '#00a65a'],
        ];

        $rows = [];
        foreach ($orders as $order) {
            foreach ($order->items as $line) {
                if (in_array($line->id, $linkedOrderItemIds, true)) {
                    continue;
                }

                $asset = $line->item instanceof Asset ? $line->item : null;
                $slug = match (true) {
                    $asset && $asset->assigned_to !== null => 'deployed',
                    (bool) $line->received_at => 'arrived',
                    default => 'ordered',
                };

                $device = $asset?->name ?: $asset?->asset_tag
                    ?: (($line->description ?: $order->order_number)
                        .((int) $line->quantity > 1 ? ' ×'.(int) $line->quantity : ''));

                $rows[] = [
                    'kind' => 'order',
                    'item_id' => null,
                    'asset_id' => null,
                    'device' => $device,
                    'device_url' => $asset ? route('hardware.show', $asset->id) : route('orders.show', $order->id),
                    'model' => $asset?->model?->name ?: ($line->description ?: '—'),
                    'wave' => $order->order_number,
                    'wave_url' => route('orders.show', $order->id),
                    'wave_color' => '#605ca8',
                    'type' => trans('admin/deployments/general.flow_order_chip'),
                    'group' => $order->order_number,
                    'context' => $order->purchaseOrder?->po_number ?: ($order->supplier?->name ?: '—'),
                    'due' => $order->expected_date?->format('Y-m-d') ?: '—',
                    'status' => $asset?->status?->name ?: ucfirst(str_replace('_', ' ', (string) $order->status)),
                    'location' => $asset?->location?->name ?: '—',
                    'stage_slug' => $slug,
                    'stage_name' => $stageMeta[$slug]['name'],
                    'stage_color' => $stageMeta[$slug]['color'],
                    'has_order' => true,
                ];
            }
        }

        return $rows;
    }

    /**
     * Unplanned capital requests: open requisitions (draft → requisitioned)
     * are money asked for but not yet a PO — ministry funding and other
     * ad-hoc asks. They surface as Planned lines so the flow shows work
     * coming before procurement even issues the order; once finance issues
     * the PO the requisition becomes an order and flows in as an order row.
     */
    private function requisitionRows(?string $fy): array
    {
        $requisitions = \App\Models\Requisition::query()
            ->whereIn('status', ['draft', 'submitted', 'requisitioned'])
            ->when($fy, fn ($q) => $q->where(fn ($w) => $w->where('fiscal_year', $fy)->orWhereNull('fiscal_year')))
            ->with('items')
            ->orderBy('requisition_number')
            ->get();

        $rows = [];
        foreach ($requisitions as $requisition) {
            foreach ($requisition->items as $line) {
                $rows[] = [
                    'kind' => 'requisition',
                    'item_id' => null,
                    'asset_id' => null,
                    'device' => ($line->description ?: $requisition->title)
                        .((int) $line->quantity > 1 ? ' ×'.(int) $line->quantity : ''),
                    'device_url' => route('requisitions.show', $requisition->id),
                    'model' => $line->description ?: '—',
                    'wave' => $requisition->requisition_number ?: ('REQ-'.$requisition->id),
                    'wave_url' => route('requisitions.show', $requisition->id),
                    'wave_color' => '#8a63d2',
                    'type' => trans('admin/deployments/general.flow_requisition_chip'),
                    'group' => $requisition->requisition_number ?: ('REQ-'.$requisition->id),
                    'context' => $requisition->title ?: ($requisition->cost_center ?: '—'),
                    'due' => $requisition->needed_by?->toDateString() ?: '—',
                    'status' => ucfirst((string) $requisition->status),
                    'location' => '—',
                    'stage_slug' => 'planned',
                    'stage_name' => trans('admin/deployments/general.flow_requisition_stage'),
                    'stage_color' => '#8a63d2',
                    'has_order' => false,
                ];
            }
        }

        return $rows;
    }

    /**
     * Past fiscal years predate deployment tracking, so the flow is
     * reconstructed from asset history — each device that moved that year
     * appears once, at its furthest stage (see HistoricalFlow).
     */
    private function historicalRows(?string $fy, $allItems): array
    {
        $onItems = $allItems->pluck('asset_id')->filter()
            ->merge($allItems->pluck('replaces_asset_id')->filter())
            ->all();

        $stageMeta = [
            'ordered' => ['name' => trans('admin/deployments/general.hist_stage_ordered'), 'color' => '#2980b9'],
            'inventoried' => ['name' => trans('admin/deployments/general.hist_stage_inventoried'), 'color' => '#f39c12'],
            'deployed' => ['name' => trans('admin/deployments/general.hist_stage_deployed'), 'color' => '#00a65a'],
        ];

        $rows = [];
        foreach ((new HistoricalFlow)->assetsByStage($fy) as $slug => $assets) {
            foreach ($assets as $asset) {
                if (in_array($asset->id, $onItems, true)) {
                    continue;
                }
                $rows[] = [
                    'kind' => 'history',
                    'item_id' => null,
                    'asset_id' => null,
                    'device' => $asset->name ?: $asset->asset_tag ?: ('#'.$asset->id),
                    'device_url' => route('hardware.show', $asset->id),
                    'model' => $asset->model?->name ?: '—',
                    'wave' => null,
                    'wave_url' => null,
                    'wave_color' => null,
                    'type' => trans('admin/deployments/general.flow_history_chip'),
                    'group' => null,
                    'context' => '—',
                    'due' => '—',
                    'status' => $asset->status?->name ?: '—',
                    'location' => $asset->location?->name ?: '—',
                    'stage_slug' => $slug,
                    'stage_name' => $stageMeta[$slug]['name'],
                    'stage_color' => $stageMeta[$slug]['color'],
                    'has_order' => false,
                ];
            }
        }

        return $rows;
    }

    /**
     * Bulk-set the holding location for devices being collected — where a
     * whole group of outgoing machines waits for its pickup run.
     */
    public function setHoldingLocation(Request $request): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer',
            'location_id' => 'required|integer|exists:locations,id',
        ]);

        $count = Asset::query()
            ->whereIn('id', $request->input('asset_ids'))
            ->update(['location_id' => (int) $request->input('location_id')]);

        return redirect()->back()->with('success', trans('admin/deployments/general.holding_location_set', [
            'count' => $count,
            'location' => Location::find((int) $request->input('location_id'))?->name,
        ]));
    }

    /** CSV of one pickup run: every device decommissioned on that date. */
    private function streamPickupCsv(string $date): StreamedResponse
    {
        $this->authorize('deployments.view');

        $assets = (new DecommissionLane)->pickupAssets($date);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="pickup-'.$date.'.csv"',
        ];

        return response()->stream(function () use ($assets, $date) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Asset Tag', 'Name', 'Serial', 'Model', 'Status', 'Lessor', 'Lease End', 'Location', 'Decommissioned']);
            foreach ($assets as $asset) {
                fputcsv($out, [
                    $asset->asset_tag,
                    $asset->name,
                    $asset->serial,
                    $asset->model?->name ?: '',
                    $asset->status?->name ?: '',
                    $asset->lessor?->name ?: '',
                    $asset->lease_end_date,
                    $asset->location?->name ?: '',
                    $date,
                ]);
            }
            fclose($out);
        }, 200, $headers);
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
        $this->authorize('deployments.view');

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
        $this->authorize('deployments.edit');

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
        $this->authorize('deployments.edit');

        $wave = new DeploymentWave;
        $wave->fill($request->all());
        $wave->created_by = auth()->id();

        if (! $wave->save()) {
            return redirect()->back()->withInput()->withErrors($wave->getErrors());
        }

        return redirect()->route('deployment-waves.show', $wave)
            ->with('success', trans('admin/deployments/general.created'));
    }

    /**
     * Tell the people in a wave that it is happening — and, by doing so, start it.
     *
     * For the Faculty Laptop Program this email IS the first step of the cycle:
     * it tells faculty the year is open, which machine is on offer and where to
     * say yes, and nothing happens until it goes out. So a real send stamps the
     * wave and moves it off `planned`, rather than being a message somebody has
     * to remember having sent.
     *
     * The body is composed here and rendered per recipient, because what each
     * person needs to know is about their own machine — which laptop they hold
     * and when its lease ends. A test renders against the first real recipient's
     * facts, since a test full of invented values would not show whether the
     * merge fields resolve, which is the thing most likely to be wrong.
     */
    public function announce(Request $request, DeploymentWave $deploymentWave, WaveAnnouncer $announcer): RedirectResponse
    {
        $this->authorize('deployments.manage');

        $validated = $request->validate([
            'subject' => 'required|string|max:191',
            'body' => 'required|string|max:65535',
            'cc' => 'nullable|array',
            'cc.*' => 'integer|exists:users,id',
            'test_recipients' => 'nullable|array',
            'test_recipients.*' => 'integer|exists:users,id',
            'test' => 'nullable|boolean',
            'save_template' => 'nullable|boolean',
            'audience' => 'nullable|string|in:'.implode(',', WaveAnnouncer::AUDIENCES),
        ]);

        // Saving the wording is its own decision, not a side effect of sending:
        // somebody rewriting the annual letter wants next year to open on it,
        // whether or not this send goes out today.
        if ($request->boolean('save_template')) {
            WaveAnnouncementTemplates::save($validated['subject'], $validated['body'], auth()->id());

            return redirect()->route('deployment-waves.show', $deploymentWave)
                ->with('success', trans('admin/deployments/general.announce_template_saved_confirm'));
        }

        $test = $request->boolean('test');
        $audience = $validated['audience'] ?? WaveAnnouncer::AUDIENCE_ALL;

        // "Nobody to chase" is a different answer from "this wave has no
        // recipients", and it is the good one: it means everybody did the
        // thing. Saying so plainly stops it reading as a failure.
        if ($announcer->recipients($deploymentWave, $audience)->isEmpty()) {
            return redirect()->route('deployment-waves.show', $deploymentWave)
                ->with($audience === WaveAnnouncer::AUDIENCE_ALL ? 'error' : 'success', trans(
                    $audience === WaveAnnouncer::AUDIENCE_ALL
                        ? 'admin/deployments/general.announce_no_recipients'
                        : 'admin/deployments/general.announce_nobody_to_chase_'.$audience
                ));
        }

        try {
            $result = $announcer->send(
                $deploymentWave,
                $validated['subject'],
                $validated['body'],
                auth()->user(),
                $test,
                [],
                \App\Models\User::whereIn('id', $validated['cc'] ?? [])->get(),
                \App\Models\User::whereIn('id', $validated['test_recipients'] ?? [])->get(),
                $audience,
            );
        } catch (\Throwable $e) {
            Log::warning('Wave announcement failed for wave '.$deploymentWave->id.': '.$e->getMessage());

            return redirect()->route('deployment-waves.show', $deploymentWave)
                ->with('error', trans('admin/deployments/general.announce_failed', ['error' => $e->getMessage()]));
        }

        if ($test) {
            return redirect()->route('deployment-waves.show', $deploymentWave)
                ->with('success', trans('admin/deployments/general.announce_test_sent', [
                    'email' => implode(', ', $result['recipients']),
                ]));
        }

        $message = trans('admin/deployments/general.announce_sent', ['count' => $result['sent']]);

        if ($result['failed'] !== []) {
            $message .= ' '.trans('admin/deployments/general.announce_partial', ['emails' => implode(', ', $result['failed'])]);
        }

        return redirect()->route('deployment-waves.show', $deploymentWave)->with('success', $message);
    }

    /**
     * Every wave, every year — the board shows one fiscal year at a time,
     * so this is where "which waves exist at all" gets answered. Also hosts
     * the two catalogs that shape waves (types, per-device stages), managed
     * inline beside the things they describe rather than behind a separate
     * Configure page.
     */
    public function waves()
    {
        $this->authorize('deployments.view');

        return view('deployment-waves.index', [
            'waves' => DeploymentWave::with(['type', 'owner'])->withCount('items')
                ->orderByDesc('fiscal_year')->orderBy('sort_order')->orderBy('name')->get(),
            'types' => DeploymentType::orderBy('sort_order')->orderBy('name')->get(),
            'stages' => DeploymentStage::orderBy('sort_order')->orderBy('name')->get(),
            'statuslabels' => Statuslabel::orderBy('name')->get(),
        ]);
    }

    /**
     * The decommissioning lane on its own address. The board keeps the same
     * lane as its bottom section; this page is for the person whose whole
     * job today is the outgoing pile. Supports the same
     * ?format=csv&decom_pickup=Y-m-d export as the board.
     */
    public function decommissioning(Request $request)
    {
        $this->authorize('deployments.view');

        $currentStartYear = now()->month >= 4 ? now()->year : now()->year - 1;
        $currentFy = sprintf('FY%d-%02d', $currentStartYear, ($currentStartYear + 1) % 100);
        $fiscalYears = collect(range($currentStartYear - 3, $currentStartYear + 3))
            ->map(fn ($y) => sprintf('FY%d-%02d', $y, ($y + 1) % 100))
            ->values()->all();

        $fy = RefreshForecast::normalizeFy($request->query('fiscal_year')) ?: $currentFy;
        $fyStartYear = RefreshForecast::fiscalYearStartYear($fy);
        $isPast = $fyStartYear < $currentStartYear;
        $isFuture = $fyStartYear > $currentStartYear;

        if ($request->query('format') === 'csv' && $request->filled('decom_pickup')) {
            return $this->streamPickupCsv((string) $request->query('decom_pickup'));
        }

        return view('reports.deployments.decommissioning', [
            'fy' => $fy,
            'fiscalYears' => $fiscalYears,
            'isPast' => $isPast,
            'decommission' => $isFuture ? null : (new DecommissionLane)->build($fy, ! $isPast),
        ]);
    }

    public function show(DeploymentWave $deploymentWave)
    {
        $this->authorize('deployments.view');

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

        $announcer = new WaveAnnouncer;
        $recipients = $announcer->recipients($deploymentWave);
        $membership = new WaveMembership;

        // Who has acted on the invitation. Keyed by user so the roster can say
        // "ordered" beside a name rather than making somebody compare two screens.
        $ordersByUser = \App\Models\StoreOrder::where('deployment_wave_id', $deploymentWave->id)
            ->orderByDesc('created_at')
            ->get()
            ->keyBy('user_id');

        return view('deployment-waves.show', [
            'wave' => $deploymentWave,
            // Who the announcement would reach, resolved from the devices rather
            // than a list: an asset checked out to a person names that person.
            'announceRecipients' => $recipients,
            // How many of them are still to act, so chasing is one click on a
            // labelled button rather than working the list out by hand against
            // the ledger and the store orders.
            'announceStalled' => [
                WaveAnnouncer::AUDIENCE_NO_APPLICATION => $announcer->recipients(
                    $deploymentWave, WaveAnnouncer::AUDIENCE_NO_APPLICATION)->count(),
                WaveAnnouncer::AUDIENCE_NO_ORDER => $announcer->recipients(
                    $deploymentWave, WaveAnnouncer::AUDIENCE_NO_ORDER)->count(),
            ],
            'announceTemplates' => WaveAnnouncementTemplates::all($deploymentWave),
            // Wave members whose device is not actually at lease end. A warning,
            // never a block: exceptions are legitimate, being invisible is not.
            'announceIneligible' => $membership->ineligible($deploymentWave, $recipients),
            'waveOrders' => $ordersByUser,
            // What each person said they would do with the old laptop, against
            // what happened to it.
            'intentRows' => (new \App\Services\UserAgreements\IntentReconciler)->rows(),
            'projectedTotal' => (float) $projected->sum(),
            'stages' => DeploymentStage::active()->ordered()->get(),
            'arrivals' => $timeline->arrivals($deploymentWave),
            'timeline' => $timeline,
        ]);
    }

    public function edit(DeploymentWave $deploymentWave)
    {
        $this->authorize('deployments.edit');

        return view('deployment-waves.edit', [
            'wave' => $deploymentWave,
            'types' => DeploymentType::active()->ordered()->get(),
        ]);
    }

    public function update(Request $request, DeploymentWave $deploymentWave): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $deploymentWave->fill($request->all());

        if (! $deploymentWave->save()) {
            return redirect()->back()->withInput()->withErrors($deploymentWave->getErrors());
        }

        return redirect()->route('deployment-waves.show', $deploymentWave)
            ->with('success', trans('admin/deployments/general.updated'));
    }

    public function destroy(DeploymentWave $deploymentWave): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $fy = $deploymentWave->fiscal_year;
        $deploymentWave->delete();

        return redirect()->route('reports.deployments', ['fiscal_year' => $fy])
            ->with('success', trans('admin/deployments/general.deleted'));
    }

    /** CSV of a single wave's items. */
    public function exportWave(DeploymentWave $deploymentWave): StreamedResponse
    {
        $this->authorize('deployments.view');

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
        $this->authorize('deployments.view');

        $forecast = new RefreshForecast;
        $fiscalYears = $forecast->availableFiscalYears();
        $fy = RefreshForecast::normalizeFy($request->query('fiscal_year')) ?: ($fiscalYears[0] ?? null);

        $candidates = $fy ? $forecast->forFiscalYear($fy) : collect();

        $waves = $fy
            ? DeploymentWave::where('fiscal_year', $fy)->withCount('items')->ordered()->get()
            : DeploymentWave::withCount('items')->ordered()->get();

        return view('reports.deployments.forecast', [
            'candidates' => $candidates,
            'fiscalYears' => $fiscalYears ?: [$fy],
            'fy' => $fy,
            'waves' => $waves,
            'types' => DeploymentType::active()->ordered()->get(),
            'timeline' => (new DeploymentTimeline)->build($waves),
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
        $this->authorize('deployments.edit');

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
            // The planning hub can shape the wave at creation: type and the
            // arrival / deploy windows land straight on the timeline.
            $wave = new DeploymentWave([
                'name' => $newWaveName,
                'fiscal_year' => $fy,
                'wave_state' => 'planned',
                'deployment_type_id' => (int) $request->input('deployment_type_id')
                    ?: DeploymentType::where('slug', 'refresh')->value('id'),
                'arrival_window_start' => $request->input('arrival_window_start') ?: null,
                'arrival_window_end' => $request->input('arrival_window_end') ?: null,
                'target_start_date' => $request->input('target_start_date') ?: null,
                'target_end_date' => $request->input('target_end_date') ?: null,
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
