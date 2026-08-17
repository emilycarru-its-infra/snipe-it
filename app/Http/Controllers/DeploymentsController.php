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
use App\Services\Deployments\StageAutomation;
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

        // Stages follow the facts before anything is counted: order lines
        // claim their wave items, received lines advance them, checkouts
        // deploy them. The buttons below are the fallback, not the path.
        if (! $isPast) {
            (new StageAutomation)->sync($fy);
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

        // The board is the waves: one row per device on a wave, nothing
        // else. The refresh backlog is Forecast's business (the note below
        // points there), open requisitions live in /capital, and order
        // lines on no wave sit in their own Incoming box rather than
        // masquerading as waves in this table. Past years still merge the
        // history reconstruction — those predate tracking entirely. The
        // rail counts THESE rows, so the chevrons and the table can never
        // disagree.
        $deviceRows = $this->deviceRows($allItems);
        if ($isPast) {
            $deviceRows = array_merge($deviceRows, $this->historicalRows($fy, $allItems));
        }

        $backlogCount = $forecast->forFiscalYear($fy)->count();

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
            'backlogCount' => $backlogCount,
            'timeline' => (new DeploymentTimeline)->build($waves),
            'downloadUrl' => route('reports.deployments', ['fiscal_year' => $fy, 'deployment_type' => $typeFilter, 'format' => 'csv']),
        ]);
    }

    /**
     * One row per device on a wave — the item carries item_id for the
     * manual stage fallback plus has_order (whether the procurement side
     * has claimed it yet).
     */
    private function deviceRows($allItems): array
    {
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
            // items.item is a morphTo: an order line can point at an Asset,
            // but also at a Component or Accessory, which carry no model()
            // relation — a blanket items.item.model eager load throws the
            // moment one such line exists. Constrain the nested loads to
            // the Asset shape; other item types render from the line itself.
            ->with([
                'items.item' => fn ($morphTo) => $morphTo->morphWith([
                    Asset::class => ['model', 'location', 'status'],
                ]),
                'supplier', 'purchaseOrder',
            ])
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
     * staged-device count, a fill bar, the device list, and any waves staging
     * there. Plus an "Unassigned" bucket for staged devices with no storage
     * location.
     *
     * "Staged" here means physically on a shelf, which is narrower than
     * "not finished" — see DeploymentItem::scopeInStorage(). A room's count is
     * a claim about floor space, so a planned refresh, an order in transit, a
     * device already in somebody's hands and a row left behind by a deleted
     * asset all have to stay out of it, or the fill bar measures intent
     * instead of occupancy.
     */
    public function storage(Request $request)
    {
        $this->authorize('deployments.view');

        $locations = Location::query()
            ->whereNotNull('storage_capacity')
            ->orderBy('name')
            ->get();

        $stagedItems = DeploymentItem::query()
            ->with(['stage', 'wave', 'asset', 'model'])
            ->inStorage()
            ->get();

        // Grouped by where the device is actually staged: its own location, or
        // failing that its wave's. Configuring the wave is the normal act, so
        // items inherit from it rather than each needing to be set by hand.
        $byLocation = $stagedItems->groupBy(fn ($item) => $item->storageLocationId());

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
            'blackouts' => \App\Models\StaffBlackout::with('user')->orderByDesc('start_date')->orderByDesc('id')->get(),
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

        // Same contract as the board: the facts move the stages before
        // the page reads them.
        (new StageAutomation)->sync(null, $deploymentWave);

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

    /**
     * Wave columns editable in place on the show page, and nowhere else —
     * the dedicated edit page is gone. Anything not listed here (slug,
     * sort_order, announced_at) is set by the system, not typed by a person.
     */
    public const INLINE_FIELDS = [
        'name',
        'fiscal_year',
        'deployment_type_id',
        'wave_state',
        'arrival_window_start',
        'arrival_window_end',
        'target_start_date',
        'target_end_date',
        'location_id',
        'storage_location_id',
        'owner_id',
        'purchase_order_id',
        'color',
        'notes',
    ];

    /**
     * One field per request, from the inline pencils on the show page. The
     * value itself is validated by the model's own $rules on save, so an
     * unknown location id or a garbage date bounces the same way the old
     * edit form did.
     */
    public function update(Request $request, DeploymentWave $deploymentWave): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $back = redirect()->route('deployment-waves.show', $deploymentWave);
        $field = (string) $request->input('field');

        if (! in_array($field, self::INLINE_FIELDS, true)) {
            return $back->with('error', trans('admin/deployments/general.update_field_rejected'));
        }

        $value = $request->input('value');
        $deploymentWave->{$field} = ($value === '' ? null : $value);

        if (! $deploymentWave->save()) {
            return $back->withErrors($deploymentWave->getErrors());
        }

        return $back->with('success', trans('admin/deployments/general.updated'));
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

        // No explicit choice opens on the CURRENT fiscal year — the sorted
        // list leads with the oldest year on record, which made the page
        // greet its reader with FY2020-21's leftovers.
        $currentStartYear = now()->month >= 4 ? now()->year : now()->year - 1;
        $currentFy = sprintf('FY%d-%02d', $currentStartYear, ($currentStartYear + 1) % 100);
        $fy = RefreshForecast::normalizeFy($request->query('fiscal_year'))
            ?: (in_array($currentFy, $fiscalYears, true) ? $currentFy : ($fiscalYears[0] ?? null));

        // Early-renewal mode, absorbed from the retired procurement forecast
        // page: criteria replace the EOL/lease window entirely, so a subset
        // of an active contract can be slotted in ahead of its dates.
        $criteria = $forecast->criteriaFromRequest($request);
        $candidates = $criteria !== []
            ? $forecast->byCriteria($criteria)
            : ($fy ? $forecast->forFiscalYear($fy) : collect());

        // The money column: each candidate priced at its comparable current
        // model's live catalog price, the old cost only as a fallback.
        $totalEstimate = (float) $candidates->sum(
            fn (Asset $asset) => $asset->replacementCostEstimate() ?? (float) ($asset->purchase_cost ?? 0)
        );

        if ($request->query('format') === 'csv') {
            return $this->streamForecastCsv($candidates, $fy, $totalEstimate);
        }

        // Devices already carrying a planned replacement line, so the page
        // can't double-book them into a second planned order.
        $plannedAssetIds = \App\Models\OrderItem::whereIn('replaces_asset_id', $candidates->pluck('id'))
            ->whereHas('order', fn ($q) => $q->where('is_planned', true))
            ->pluck('replaces_asset_id')
            ->all();

        $waves = $fy
            ? DeploymentWave::where('fiscal_year', $fy)->withCount('items')->ordered()->get()
            : DeploymentWave::withCount('items')->ordered()->get();

        // Let the facts claim what they can first; what's left below is
        // genuinely unassigned. Incoming orders belong to planning — they
        // are future deployments that haven't been given a wave yet.
        $incomingOrders = collect();
        if ($fy) {
            (new StageAutomation)->sync($fy);
            $waveItems = DeploymentItem::query()
                ->whereIn('wave_id', $waves->pluck('id')->all() ?: [0])
                ->get(['id', 'order_item_id']);
            $incomingOrders = collect($this->orderRows($fy, $waveItems))->groupBy('group');
        }

        return view('reports.deployments.planning', [
            'candidates' => $candidates,
            'incomingOrders' => $incomingOrders,
            'nextFy' => $fy ? self::nextFiscalYear($fy) : null,
            'fiscalYears' => $fiscalYears ?: [$fy],
            'fy' => $fy,
            'waves' => $waves,
            'types' => DeploymentType::active()->ordered()->get(),
            'timeline' => (new DeploymentTimeline)->build($waves),
            'leaseColumnPresent' => RefreshForecast::leaseEndColumn() !== null,
            'totalEstimate' => $totalEstimate,
            'plannedAssetIds' => $plannedAssetIds,
            'filterFields' => $forecast->filterFields(),
            'filterValues' => $forecast->filterValues(),
            'activeCriteria' => $criteria,
            'earlyRenewalMode' => $criteria !== [],
        ]);
    }

    /**
     * The forecast as finance receives it — the same column shape the
     * retired procurement forecast report exported.
     *
     * @param  \Illuminate\Support\Collection<int, Asset>  $candidates
     */
    private function streamForecastCsv($candidates, ?string $fy, float $totalEstimate): StreamedResponse
    {
        return new StreamedResponse(function () use ($candidates, $totalEstimate) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                trans('admin/purchase-orders/general.forecast_asset_tag'),
                trans('admin/purchase-orders/general.forecast_asset_name'),
                trans('admin/purchase-orders/general.forecast_model'),
                trans('admin/purchase-orders/general.forecast_serial'),
                trans('admin/purchase-orders/general.forecast_purchase_date'),
                trans('admin/deployments/general.source_date'),
                trans('admin/purchase-orders/general.forecast_estimate'),
                trans('admin/purchase-orders/general.forecast_estimate_basis'),
                trans('admin/purchase-orders/general.forecast_status'),
                trans('general.supplier'),
            ]);

            foreach ($candidates as $asset) {
                $catalog = $asset->model?->refreshCatalogItem;
                $estimate = $asset->replacementCostEstimate() ?? (float) ($asset->purchase_cost ?? 0);
                fputcsv($handle, [
                    (string) $asset->asset_tag,
                    (string) $asset->name,
                    (string) $asset->model?->name,
                    (string) $asset->serial,
                    $asset->purchase_date ? Carbon::parse($asset->purchase_date)->toDateString() : '',
                    (string) $asset->source_date,
                    number_format($estimate, 2, '.', ''),
                    $catalog
                        ? trans('admin/purchase-orders/general.forecast_basis_catalog', ['name' => $catalog->name])
                        : trans('admin/purchase-orders/general.forecast_basis_original'),
                    (string) $asset->status?->name,
                    (string) $asset->supplier?->name,
                ]);
            }

            fputcsv($handle, [trans('admin/orders/general.total'), '', '', '', '', '', number_format($totalEstimate, 2, '.', ''), '', '', '']);
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="refresh-forecast-'.($fy ?: 'all').'-'.date('Y-m-d').'.csv"',
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

    /** The fiscal year after the given one — FY2026-27 → FY2027-28. */
    public static function nextFiscalYear(string $fy): string
    {
        $startYear = RefreshForecast::fiscalYearStartYear($fy) + 1;

        return sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);
    }

    /**
     * Push devices to the next fiscal year — the planning decision that a
     * refresh due this year waits a year. Recorded as an asset-level lease
     * decision (type: extend) carrying the target FY, so the planning list
     * for this FY drops them and next FY's picks them up, and the decision
     * itself lives in the same log every other lease decision does —
     * visible, reversible, API-readable.
     */
    public function deferFromPlanning(Request $request): RedirectResponse
    {
        $this->authorize('deployments.edit');

        $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer',
            'fiscal_year' => 'required|string',
        ]);

        $fy = RefreshForecast::normalizeFy($request->input('fiscal_year'));
        $targetFy = self::nextFiscalYear($fy);

        $deferred = 0;
        foreach ($request->input('asset_ids') as $assetId) {
            $asset = Asset::find((int) $assetId);
            if (! $asset) {
                continue;
            }

            \App\Models\LeaseDecision::updateOrCreate(
                ['asset_id' => $asset->id, 'decision_type' => 'extend'],
                [
                    'contract_reference' => $asset->lease_contract_id ?: ($asset->asset_tag ?: ('ASSET-'.$asset->id)),
                    'status' => 'approved',
                    'decision_date' => now()->toDateString(),
                    'deferred_to_fy' => $targetFy,
                    'notes' => trans('admin/deployments/general.defer_note', ['from' => $fy, 'to' => $targetFy]),
                ]
            );
            $deferred++;
        }

        return redirect()->route('deployments.planning', ['fiscal_year' => $fy])
            ->with('success', trans('admin/deployments/general.defer_done', ['count' => $deferred, 'fy' => $targetFy]));
    }

    /** Default FY label for a new wave (current ECU fiscal year). */
    private function defaultFiscalYear(): string
    {
        $now = Carbon::now();
        $startYear = $now->month >= 4 ? $now->year : $now->year - 1;

        return sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);
    }
}
