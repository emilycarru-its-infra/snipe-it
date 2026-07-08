<?php

namespace App\Http\Controllers;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Company;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\License;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\Statuslabel;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

/**
 * Admin dashboard.
 *
 * Five rows: KPI strip, lifecycle funnel, recent activity, action queue +
 * procurement glance, asset breakdowns. Fork-only widgets (procurement,
 * custom-field rollups) auto-detect their tables/columns so the controller
 * stays upstream-safe.
 */
class DashboardController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (! auth()->user()->hasAccess('admin')) {
            Session::reflash();
            return redirect()->intended('account/view-assets');
        }

        $counts = [
            'asset'       => Asset::count(),
            'accessory'   => Accessory::count(),
            'license'     => License::assetcount(),
            'consumable'  => Consumable::count(),
            'component'   => Component::count(),
            'user'        => Company::scopeCompanyables(auth()->user())->count(),
        ];
        $counts['grand_total'] = $counts['asset'] + $counts['accessory']
            + $counts['license'] + $counts['consumable'];

        if ((! file_exists(storage_path().'/oauth-private.key')) || (! file_exists(storage_path().'/oauth-public.key'))) {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('passport:install', ['--no-interaction' => true]);
        }

        // Fresh installs (and the in-memory test database) may not have a
        // settings row yet; fall back to an empty Setting so the typed
        // builder signatures and Asset::DueForAudit/Checkin scopes can
        // safely dereference fields like audit_warning_days as null/0.
        $settings = Setting::getSettings() ?? new Setting;

        return view('dashboard')
            ->with('asset_stats', null)
            ->with('counts', $counts)
            ->with('categoryTiles', $this->buildCategoryTiles())
            ->with('kpis', $this->buildKpis($settings))
            ->with('lifecycle', $this->buildLifecycle())
            ->with('actionQueue', $this->buildActionQueue($settings))
            ->with('procurement', $this->buildProcurement())
            ->with('customBreakdowns', $this->buildCustomBreakdowns());
    }

    /**
     * Quick-access category cards rendered under the Asset Lifecycle box, in the
     * same card style as Needs Attention. Categories are resolved by name
     * (case-insensitive) so the dashboard survives id changes and the wrong-id
     * problem; any name not present is silently skipped. The count mirrors the
     * categories index (showable assets, deleted excluded), and each card
     * deep-links to /categories/{id}. Icon (Font Awesome 5 free) and accent
     * colour are per-category — edit the map to retheme.
     */
    private function buildCategoryTiles(): array
    {
        // Ordered display list: name => [fa icon, left-border accent colour].
        // Names match the Snipe category records exactly (singular, as created);
        // colours come from the dashboard palette.
        $wanted = [
            'Desktop' => ['fa-desktop',    '#0073b7'],
            'Laptop'  => ['fa-laptop',     '#00a65a'],
            'Display' => ['fa-tv',         '#dd4b39'],
            'Tablet'  => ['fa-tablet-alt', '#605ca8'],
            'Phone'   => ['fa-mobile-alt', '#39cccc'],
            'Printer' => ['fa-print',      '#f39c12'],
        ];

        // sortBy('assets_count') before keyBy so that when duplicate category
        // records share a name (there are empty "Display"/"Scanner" dupes), the
        // populated one is iterated last and wins the key.
        $categories = Category::where('category_type', 'asset')
            ->whereIn(DB::raw('LOWER(name)'), array_map('strtolower', array_keys($wanted)))
            ->withCount('showableAssets as assets_count')
            ->get()
            ->sortBy('assets_count')
            ->keyBy(fn ($c) => strtolower($c->name));

        $tiles = [];
        foreach ($wanted as $name => [$icon, $color]) {
            $category = $categories->get(strtolower($name));
            if (! $category) {
                continue;
            }
            $tiles[] = [
                'id'    => $category->id,
                'name'  => $category->name,
                'icon'  => $icon,
                'color' => $color,
                'count' => (int) $category->assets_count,
            ];
        }

        return $tiles;
    }

    private function buildKpis(Setting $settings): array
    {
        $total = Asset::AssetsForShow()->count();

        $deployed = Asset::join('status_labels', 'assets.status_id', '=', 'status_labels.id')
            ->whereNotNull('assets.assigned_to')
            ->where('status_labels.deployable', 1)
            ->whereNull('status_labels.deleted_at')
            ->whereNull('assets.deleted_at')
            ->count();

        $readyToDeploy = Asset::join('status_labels', 'assets.status_id', '=', 'status_labels.id')
            ->whereNull('assets.assigned_to')
            ->where('status_labels.deployable', 1)
            ->whereNull('status_labels.deleted_at')
            ->whereNull('assets.deleted_at')
            ->count();

        $notCheckedOut = Asset::AssetsForShow()->whereNull('assigned_to')->count();

        $pending = Asset::join('status_labels', 'assets.status_id', '=', 'status_labels.id')
            ->where('status_labels.pending', 1)
            ->whereNull('status_labels.deleted_at')
            ->whereNull('assets.deleted_at')
            ->count();

        $damagedMissing = Asset::join('status_labels', 'assets.status_id', '=', 'status_labels.id')
            ->whereIn(DB::raw('LOWER(status_labels.name)'), ['damaged', 'missing'])
            ->whereNull('assets.deleted_at')
            ->count();

        $overdueAudit = Asset::OverdueForAudit()->count();
        $dueAudit     = Asset::DueForAudit($settings)->count();
        $dueCheckin   = Asset::DueForCheckin($settings)->count();

        $openMaint       = Maintenance::whereNull('completion_date')->count();
        $inProgressMaint = Maintenance::whereNull('completion_date')
            ->where('start_date', '<=', Carbon::now())->count();

        $createdLast7 = Asset::where('created_at', '>=', Carbon::now()->subDays(7))->count();
        $createdPrev7 = Asset::whereBetween('created_at', [
            Carbon::now()->subDays(14), Carbon::now()->subDays(7),
        ])->count();

        return [
            'total'                   => $total,
            'deployed'                => $deployed,
            'ready_to_deploy'         => $readyToDeploy,
            'not_checked_out'         => $notCheckedOut,
            'pending'                 => $pending,
            'damaged_missing'         => $damagedMissing,
            'overdue_audit'           => $overdueAudit,
            'due_audit'               => $dueAudit,
            'due_checkin'             => $dueCheckin,
            'open_maintenance'        => $openMaint,
            'in_progress_maintenance' => $inProgressMaint,
            'created_last_7'          => $createdLast7,
            'created_prev_7'          => $createdPrev7,
        ];
    }

    /**
     * Lifecycle funnel — bucket every status_label into a stage so it renders
     * as a horizontal stacked bar instead of an unreadable pie. Stages are
     * derived from the status label NAME so this generalises across Snipe
     * instances; the meta flags are the fallback.
     */
    private function buildLifecycle(): array
    {
        $rows = Statuslabel::leftJoin('assets', function ($join) {
                $join->on('assets.status_id', '=', 'status_labels.id')
                     ->whereNull('assets.deleted_at');
            })
            ->whereNull('status_labels.deleted_at')
            ->select(
                'status_labels.id',
                'status_labels.name',
                'status_labels.color',
                'status_labels.deployable',
                'status_labels.pending',
                'status_labels.archived',
                DB::raw('COUNT(assets.id) as asset_count')
            )
            ->groupBy('status_labels.id', 'status_labels.name', 'status_labels.color',
                'status_labels.deployable', 'status_labels.pending', 'status_labels.archived')
            ->get();

        $stages = [
            'new'        => ['label' => 'New',        'color' => '#3c8dbc', 'items' => [], 'count' => 0],
            'active'     => ['label' => 'Active',     'color' => '#00a65a', 'items' => [], 'count' => 0],
            'storage'    => ['label' => 'Storage',    'color' => '#dd4b39', 'items' => [], 'count' => 0],
            'processing' => ['label' => 'Processing', 'color' => '#b03cb0', 'items' => [], 'count' => 0],
            'damaged'    => ['label' => 'Damaged',    'color' => '#f39c12', 'items' => [], 'count' => 0],
            'missing'    => ['label' => 'Missing',    'color' => '#f39c12', 'items' => [], 'count' => 0],
            'archived'   => ['label' => 'Archived',   'color' => '#777777', 'items' => [], 'count' => 0],
            'other'      => ['label' => 'Other',      'color' => '#999999', 'items' => [], 'count' => 0],
        ];

        foreach ($rows as $row) {
            $name = strtolower($row->name);
            if (str_starts_with($name, 'new')) {
                $stage = 'new';
            } elseif (str_starts_with($name, 'active')) {
                $stage = 'active';
            } elseif (str_starts_with($name, 'processing')) {
                $stage = 'processing';
            } elseif (str_contains($name, 'storage')) {
                $stage = 'storage';
            } elseif (str_contains($name, 'damaged')) {
                $stage = 'damaged';
            } elseif (str_contains($name, 'missing')) {
                $stage = 'missing';
            } elseif ($row->archived) {
                $stage = 'archived';
            } elseif ($row->pending) {
                $stage = 'new';
            } elseif ($row->deployable) {
                $stage = 'active';
            } else {
                $stage = 'other';
            }
            $count = (int) $row->asset_count;
            $stages[$stage]['items'][] = [
                'id'    => $row->id,
                'name'  => $row->name,
                'color' => $row->color ?: $stages[$stage]['color'],
                'count' => $count,
            ];
            $stages[$stage]['count'] += $count;
        }

        foreach ($stages as $key => &$stage) {
            usort($stage['items'], fn($a, $b) => $b['count'] <=> $a['count']);
            if ($stage['count'] === 0 && empty($stage['items'])) {
                unset($stages[$key]);
            }
        }

        return $stages;
    }

    private function buildActionQueue(Setting $settings): array
    {
        $now = Carbon::now();

        // The warranty expiry is purchase_date + warranty_months months. SQL
        // dialects don't agree on date arithmetic — branch by driver so the
        // dashboard works on production MySQL and on the SQLite-backed test
        // suite alike.
        $driver = DB::connection()->getDriverName();
        $warrantyExpr = match ($driver) {
            'sqlite' => "date(purchase_date, '+' || warranty_months || ' months')",
            'pgsql' => "(purchase_date + (warranty_months || ' months')::interval)",
            default => 'DATE_ADD(purchase_date, INTERVAL warranty_months MONTH)',
        };

        $warrantyBucket = function (int $days) use ($now, $warrantyExpr) {
            return Asset::AssetsForShow()
                ->whereNotNull('warranty_months')
                ->whereNotNull('purchase_date')
                ->whereRaw($warrantyExpr.' BETWEEN ? AND ?', [
                    $now->copy()->toDateString(),
                    $now->copy()->addDays($days)->toDateString(),
                ])->count();
        };

        // The lease-end column is a 'Y-m-d' string custom field, so a
        // lexicographic BETWEEN works on every driver without STR_TO_DATE.
        $leaseColumn = Schema::hasColumn('assets', '_snipeit_lease_end_date_14')
            ? '_snipeit_lease_end_date_14' : null;
        $leaseBucket = function (int $days) use ($now, $leaseColumn) {
            if (! $leaseColumn) return null;
            return Asset::AssetsForShow()
                ->whereNotNull($leaseColumn)
                ->where($leaseColumn, '!=', '')
                ->whereBetween($leaseColumn, [
                    $now->copy()->toDateString(),
                    $now->copy()->addDays($days)->toDateString(),
                ])->count();
        };

        $stuckProcessing = Asset::join('status_labels', 'assets.status_id', '=', 'status_labels.id')
            ->where(DB::raw('LOWER(status_labels.name)'), 'like', 'processing %')
            ->where('assets.updated_at', '<', $now->copy()->subDays(14))
            ->whereNull('assets.deleted_at')
            ->count();

        return [
            'warranty_30'       => $warrantyBucket(30),
            'warranty_60'       => $warrantyBucket(60),
            'warranty_90'       => $warrantyBucket(90),
            'lease_30'          => $leaseBucket(30),
            'lease_60'          => $leaseBucket(60),
            'lease_90'          => $leaseBucket(90),
            'audit_overdue'     => Asset::OverdueForAudit()->count(),
            'audit_due_30'      => Asset::DueForAudit($settings)->count(),
            'checkin_due'       => Asset::DueForCheckin($settings)->count(),
            'stuck_processing'  => $stuckProcessing,
            'open_maint'        => Maintenance::whereNull('completion_date')->count(),
            'in_progress_maint' => Maintenance::whereNull('completion_date')
                ->where('start_date', '<=', $now)->count(),
            'scheduled_maint'   => Maintenance::whereNull('completion_date')
                ->where('start_date', '>', $now)->count(),
        ];
    }

    private function buildProcurement(): ?array
    {
        if (! Schema::hasTable('purchase_orders')) {
            return null;
        }

        $hasOrders = Schema::hasTable('orders');

        // Listings below the procurement glance are vendor orders (the
        // operational unit), not purchase orders (the budget unit). Top-of-card
        // counts stay broken out separately so the relationship is visible.
        $openOrdersList = $hasOrders
            ? DB::table('orders')
                ->leftJoin('suppliers', 'orders.supplier_id', '=', 'suppliers.id')
                ->whereIn('orders.status', ['ordered', 'shipped', 'partially_received'])
                ->whereNull('orders.deleted_at')
                ->orderByDesc('orders.order_date')
                ->limit(5)
                ->select(
                    'orders.id', 'orders.order_number', 'orders.status',
                    'orders.order_date', 'suppliers.name as supplier_name'
                )->get()
            : collect();

        $recentOrdersList = $hasOrders
            ? DB::table('orders')
                ->leftJoin('suppliers', 'orders.supplier_id', '=', 'suppliers.id')
                ->whereNull('orders.deleted_at')
                ->orderByDesc('orders.order_date')
                ->limit(5)
                ->select(
                    'orders.id', 'orders.order_number', 'orders.status',
                    'orders.order_date', 'suppliers.name as supplier_name'
                )->get()
            : collect();

        $openOrderCount = $hasOrders
            ? DB::table('orders')
                ->whereIn('status', ['ordered', 'shipped', 'partially_received'])
                ->whereNull('deleted_at')->count()
            : 0;

        $unmatchedInvoices = Schema::hasTable('order_invoices')
            ? DB::table('order_invoices')->whereNull('purchase_order_id')->count()
            : 0;

        return [
            'open_orders'        => $openOrdersList,
            'recent_orders'      => $recentOrdersList,
            'open_orders_count'  => $openOrderCount,
            'unmatched_invoices' => $unmatchedInvoices,
            'open_po_count'      => DB::table('purchase_orders')
                ->whereIn('status', ['open', 'amended'])
                ->whereNull('deleted_at')->count(),
        ];
    }

    /**
     * Custom-field rollups. Each candidate is auto-skipped if the column
     * isn't present, so this is safe on upstream Snipe.
     */
    private function buildCustomBreakdowns(): array
    {
        // Order matches the dashboard render order: Fleet, Ownership, DMS.
        $candidates = [
            'fleet'          => ['column' => '_snipeit_fleet_41',                      'label' => 'Fleet',                       'limit' => 12],
            'ownership_type' => ['column' => '_snipeit_ownership_type_20',             'label' => 'Ownership',                   'limit' => 8],
            'dms'            => ['column' => '_snipeit_device_management_service_44',  'label' => 'Device Management Service',   'limit' => 8],
        ];

        $out = [];
        foreach ($candidates as $key => $spec) {
            if (! Schema::hasColumn('assets', $spec['column'])) {
                continue;
            }
            $rows = Asset::AssetsForShow()
                ->whereNotNull($spec['column'])
                ->where($spec['column'], '!=', '')
                ->select($spec['column'].' as bucket', DB::raw('COUNT(*) as cnt'))
                ->groupBy('bucket')
                ->orderByDesc('cnt')
                ->limit($spec['limit'])
                ->get();
            if ($rows->isEmpty()) {
                continue;
            }
            $out[$key] = [
                'label'   => $spec['label'],
                'column'  => $spec['column'],
                'buckets' => $rows->map(fn($r) => ['name' => $r->bucket, 'count' => (int) $r->cnt])->all(),
            ];
        }
        return $out;
    }
}
