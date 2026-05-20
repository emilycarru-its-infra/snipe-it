<?php

namespace App\Http\Controllers;

use App\Models\Accessory;
use App\Models\Asset;
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

        $settings = Setting::getSettings();

        return view('dashboard')
            ->with('asset_stats', null)
            ->with('counts', $counts)
            ->with('kpis', $this->buildKpis($settings))
            ->with('lifecycle', $this->buildLifecycle())
            ->with('actionQueue', $this->buildActionQueue($settings))
            ->with('procurement', $this->buildProcurement())
            ->with('customBreakdowns', $this->buildCustomBreakdowns());
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

        $warrantyBucket = function (int $days) use ($now) {
            return Asset::AssetsForShow()
                ->whereNotNull('warranty_months')
                ->whereNotNull('purchase_date')
                ->whereRaw('DATE_ADD(purchase_date, INTERVAL warranty_months MONTH) BETWEEN ? AND ?', [
                    $now->copy()->toDateString(),
                    $now->copy()->addDays($days)->toDateString(),
                ])->count();
        };

        $leaseColumn = Schema::hasColumn('assets', '_snipeit_lease_end_date_14')
            ? '_snipeit_lease_end_date_14' : null;
        $leaseBucket = function (int $days) use ($now, $leaseColumn) {
            if (! $leaseColumn) return null;
            return Asset::AssetsForShow()
                ->whereNotNull($leaseColumn)
                ->where($leaseColumn, '!=', '')
                ->whereRaw("STR_TO_DATE(`$leaseColumn`, '%Y-%m-%d') BETWEEN ? AND ?", [
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

        $openPos = DB::table('purchase_orders')
            ->leftJoin('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->whereIn('purchase_orders.status', ['open', 'amended'])
            ->whereNull('purchase_orders.deleted_at')
            ->orderByDesc('purchase_orders.order_date')
            ->limit(5)
            ->select(
                'purchase_orders.id', 'purchase_orders.po_number', 'purchase_orders.title',
                'purchase_orders.status', 'purchase_orders.order_date', 'purchase_orders.budget',
                'suppliers.name as supplier_name'
            )->get();

        $recentPos = DB::table('purchase_orders')
            ->leftJoin('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->whereNull('purchase_orders.deleted_at')
            ->orderByDesc('purchase_orders.order_date')
            ->limit(5)
            ->select(
                'purchase_orders.id', 'purchase_orders.po_number', 'purchase_orders.title',
                'purchase_orders.status', 'purchase_orders.order_date',
                'suppliers.name as supplier_name'
            )->get();

        $openOrders = Schema::hasTable('orders')
            ? DB::table('orders')
                ->whereIn('status', ['ordered', 'shipped', 'partially_received'])
                ->whereNull('deleted_at')->count()
            : 0;

        $unmatchedInvoices = Schema::hasTable('order_invoices')
            ? DB::table('order_invoices')->whereNull('purchase_order_id')->count()
            : 0;

        return [
            'open_pos'           => $openPos,
            'recent_pos'         => $recentPos,
            'open_orders'        => $openOrders,
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
        $candidates = [
            'chip'           => ['column' => '_snipeit_chip_7',                        'label' => 'Chip',       'limit' => 10],
            'memory'         => ['column' => '_snipeit_memory_9',                      'label' => 'Memory',     'limit' => 8],
            'storage'        => ['column' => '_snipeit_storage_10',                    'label' => 'Storage',    'limit' => 8],
            'ownership_type' => ['column' => '_snipeit_ownership_type_20',             'label' => 'Ownership',  'limit' => 6],
            'fleet'          => ['column' => '_snipeit_fleet_41',                      'label' => 'Fleet',      'limit' => 10],
            'mdm'            => ['column' => '_snipeit_device_management_service_44',  'label' => 'MDM',        'limit' => 6],
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
