<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Transformers\AgreementObservationsTransformer;
use App\Http\Transformers\LeaseObservationsTransformer;
use App\Http\Transformers\ProcurementObservationsTransformer;
use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use App\Models\Contract;
use App\Models\ContractAttribute;
use App\Models\ContractSerial;
use App\Models\ExhibitProject;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\UserAgreement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Read-only source facts; no recipient lookup, financial policy or workflow transition. */
class WorkflowObservationsController extends Controller
{
    public function catalogItems(Request $request): JsonResponse
    {
        $this->authorize('view', CatalogItem::class);

        $fields = ['id', 'name', 'category', 'supplier_id', 'manufacturer_id', 'model_id',
            'vendor_sku', 'mfr_part_number', 'unit_cost', 'estimated_cost', 'currency',
            'price_type', 'product_type', 'quoted_at', 'expires_at',
            'is_active', 'show_in_store', 'self_serve', 'store_sort'];

        return $this->page($request, CatalogItem::query()->active()->select($fields), function (CatalogItem $row) use ($fields) {
            $facts = $row->only($fields);
            foreach (['quoted_at', 'expires_at'] as $key) {
                $facts[$key] = $row->{$key}?->format('Y-m-d');
            }
            foreach (['id', 'supplier_id', 'manufacturer_id', 'model_id'] as $key) {
                $facts[$key] = $facts[$key] === null ? null : (string) $facts[$key];
            }

            return $facts;
        });
    }

    public function purchaseOrders(Request $request): JsonResponse
    {
        $this->authorize('view', Order::class);

        return $this->procurementPage($request, Company::scopeCompanyables(PurchaseOrder::query()), 'purchase-orders');
    }

    public function orders(Request $request): JsonResponse
    {
        $this->authorize('view', Order::class);

        return $this->procurementPage($request, $this->visibleOrders(Order::query()), 'orders');
    }

    public function orderInvoices(Request $request): JsonResponse
    {
        $this->authorize('view', Order::class);

        return $this->procurementPage($request, $this->visibleInvoices(OrderInvoice::query()), 'order-invoices');
    }

    public function orderShipments(Request $request): JsonResponse
    {
        $this->authorize('view', Order::class);

        return $this->procurementPage($request, $this->visibleShipments(OrderShipment::query()), 'order-shipments');
    }

    public function orderLines(Request $request): JsonResponse
    {
        $this->authorize('view', Order::class);

        $query = $this->visiblePurchaseOrderLink(OrderItem::query())
            ->whereHas('order', fn (Builder $parent) => $this->visibleOrders($parent))
            ->where(function (Builder $links) {
                $links->whereNull('invoice_id')->orWhereHas('invoice', function (Builder $invoice) {
                    $this->visibleInvoices($invoice)->whereColumn('order_invoices.order_id', 'order_items.order_id');
                });
            })
            ->where(function (Builder $links) {
                $links->whereNull('shipment_id')->orWhereHas('shipment', function (Builder $shipment) {
                    $this->visibleShipments($shipment)->whereColumn('order_shipments.order_id', 'order_items.order_id');
                });
            });

        return $this->procurementPage($request, $query, 'order-lines');
    }

    /** Split booking links need a visible live PO; null remains a genuine unlinked source fact. */
    private function visiblePurchaseOrderLink(Builder $query): Builder
    {
        return $query->where(function (Builder $links) {
            $links->whereNull('purchase_order_id')->orWhereHas('purchaseOrder', fn (Builder $parent) => Company::scopeCompanyables($parent));
        });
    }

    private function visibleOrders(Builder $query): Builder
    {
        return $this->visiblePurchaseOrderLink(Company::scopeCompanyables($query));
    }

    private function visibleInvoices(Builder $query): Builder
    {
        return $this->visiblePurchaseOrderLink($query)
            ->whereHas('order', fn (Builder $parent) => $this->visibleOrders($parent));
    }

    private function visibleShipments(Builder $query): Builder
    {
        return $query->whereHas('order', fn (Builder $parent) => $this->visibleOrders($parent));
    }

    private function procurementPage(Request $request, Builder $query, string $family): JsonResponse
    {
        $transformer = new ProcurementObservationsTransformer;
        $query->select(array_diff(ProcurementObservationsTransformer::FIELDS[$family], ['observed_line_count', 'observed_invoice_count', 'observed_shipment_count']));
        if ($family === 'orders') {
            // Count all children on this visible parent, even when a child link is outside
            // the exported scope. Readers must not silently migrate a partial order.
            $query->withCount(['items as observed_line_count', 'invoices as observed_invoice_count', 'shipments as observed_shipment_count']);
        }

        return $this->page($request, $query, fn (Model $row) => $transformer->transform($row, $family), maxOffset: 50000);
    }

    public function leases(Request $request): JsonResponse
    {
        $this->authorize('view', Asset::class);

        // Asset's company and soft-delete scopes remain in force. Presence of stored facts
        // does not assert that a lease is currently open or that a decommission was a return.
        $query = Asset::query()->where(function (Builder $query) {
            $query->whereNotNull('lessor_id')
                ->orWhereRaw('LOWER(ownership_type) LIKE ?', ['%lease%'])
                ->orWhere(function (Builder $contracts) {
                    $contracts->whereNotNull('lease_contract_id')->where('lease_contract_id', '!=', '');
                })
                ->orWhere(function (Builder $contracts) {
                    $contracts->whereNotNull('lease_contract_name')->where('lease_contract_name', '!=', '');
                })
                ->orWhereNotNull('lease_end_date')->orWhereNotNull('lease_rent')->orWhereNotNull('buyout_cost');
        })->select(LeaseObservationsTransformer::FIELDS);
        $transformer = new LeaseObservationsTransformer;

        return $this->page($request, $query, fn (Asset $row) => $transformer->transform($row), maxOffset: 20000);
    }

    public function exhibitProjects(Request $request): JsonResponse
    {
        $this->authorize('view', Order::class);

        $fields = ['id', 'exhibit_id', 'year', 'user_id', 'student_name', 'asset_id',
            'status_id', 'project_type_id', 'project_details', 'requested_device',
            'peripherals', 'submitted_file', 'approved', 'tdx_id', 'notes'];

        return $this->page($request, ExhibitProject::query()->select($fields), function (ExhibitProject $row) use ($fields) {
            $facts = $row->only($fields);
            foreach (['id', 'exhibit_id', 'user_id', 'asset_id', 'status_id', 'project_type_id'] as $key) {
                $facts[$key] = $facts[$key] === null ? null : (string) $facts[$key];
            }

            return $facts;
        });
    }

    public function userAgreements(Request $request): JsonResponse
    {
        $this->authorize('view', Order::class);

        $query = UserAgreement::query()->select([
            ...AgreementObservationsTransformer::FIELDS, 'pdf_path', 'signed_pdf_path',
        ]);
        $transformer = new AgreementObservationsTransformer;

        return $this->page($request, $query, fn (UserAgreement $row) => $transformer->transform($row));
    }

    public function consumableTransactions(Request $request): JsonResponse
    {
        $this->authorize('view', Consumable::class);

        // The relation retains CompanyableScope. Deleted parents are outside this source scope.
        $query = ConsumableTransaction::query()
            ->whereHas('consumable', fn (Builder $parent) => $parent->whereNull('consumables.deleted_at'))
            ->select(['id', 'consumable_id', 'asset_id', 'gl_code', 'quantity', 'unit_cost',
                'total_cost', 'transaction_date', 'fiscal_year', 'status', 'notes']);

        return $this->page($request, $query, fn (ConsumableTransaction $row) => [
            'id' => (string) $row->id,
            'consumable_id' => (string) $row->consumable_id,
            'asset_id' => (string) $row->asset_id,
            'gl_code' => $row->gl_code,
            'quantity' => $row->quantity,
            'unit_cost' => $row->unit_cost,
            'total_cost' => $row->total_cost,
            'transaction_date' => $row->transaction_date?->format('Y-m-d'),
            'fiscal_year' => $row->fiscal_year,
            'status' => $row->status,
            'notes' => $row->notes,
        ]);
    }

    /**
     * Each asset a live contract covers, with the dates and notes of that coverage. A link to a
     * soft-deleted asset is kept and marked, since the coverage was recorded; an asset outside
     * the caller's companies is left out.
     */
    public function contractAssets(Request $request): JsonResponse
    {
        $this->authorize('view', Contract::class);

        $assets = Asset::withTrashed()->select('id');
        $query = DB::table('contract_asset')
            ->join('contracts', 'contracts.id', '=', 'contract_asset.contract_id')
            ->leftJoin('assets', 'assets.id', '=', 'contract_asset.asset_id')
            ->whereNull('contracts.deleted_at')
            ->whereIn('contract_asset.asset_id', $assets)
            ->select(['contract_asset.id', 'contract_asset.contract_id', 'contract_asset.asset_id',
                'contract_asset.valid_from', 'contract_asset.valid_to', 'contract_asset.notes',
                'assets.deleted_at as asset_deleted_at']);

        return $this->page($request, $query, fn (object $row) => [
            'id' => (string) $row->id,
            'contract_id' => (string) $row->contract_id,
            'asset_id' => (string) $row->asset_id,
            'valid_from' => $row->valid_from === null ? null : substr((string) $row->valid_from, 0, 10),
            'valid_to' => $row->valid_to === null ? null : substr((string) $row->valid_to, 0, 10),
            'notes' => $row->notes,
            'asset_deleted' => $row->asset_deleted_at !== null,
        ], maxOffset: 50000, order: 'contract_asset.id');
    }

    /** Each serial number a live contract names, with where it was read. */
    public function contractSerials(Request $request): JsonResponse
    {
        $this->authorize('view', Contract::class);

        $query = ContractSerial::query()->whereHas('contract')
            ->select(['id', 'contract_id', 'serial', 'source', 'notes']);

        return $this->page($request, $query, fn (ContractSerial $row) => [
            'id' => (string) $row->id,
            'contract_id' => (string) $row->contract_id,
            'serial' => $row->serial,
            'source' => $row->source,
            'notes' => $row->notes,
        ], maxOffset: 50000);
    }

    /** Each stored attribute of a live contract, as its name and value. */
    public function contractAttributes(Request $request): JsonResponse
    {
        $this->authorize('view', Contract::class);

        $query = ContractAttribute::query()->whereHas('contract')
            ->select(['id', 'contract_id', 'name', 'value']);

        return $this->page($request, $query, fn (ContractAttribute $row) => [
            'id' => (string) $row->id,
            'contract_id' => (string) $row->contract_id,
            'name' => $row->name,
            'value' => $row->value,
        ], maxOffset: 50000);
    }

    /**
     * Each seat of a live licence in stable id order: who or what holds it, as the holder's id
     * only, whether it was retired at checkin, its notes and when it last changed (the checkout
     * time the seat list shows). No person field beyond the id leaves.
     */
    public function licenseSeats(Request $request): JsonResponse
    {
        $this->authorize('view', License::class);

        $query = LicenseSeat::query()->whereHas('license')
            ->select(['id', 'license_id', 'assigned_to', 'asset_id', 'unreassignable_seat', 'notes', 'updated_at']);

        return $this->page($request, $query, fn (LicenseSeat $row) => [
            'id' => (string) $row->id,
            'license_id' => (string) $row->license_id,
            'assigned_to' => $row->assigned_to === null ? null : (string) $row->assigned_to,
            'asset_id' => $row->asset_id === null ? null : (string) $row->asset_id,
            'unreassignable' => (bool) $row->unreassignable_seat,
            'notes' => $row->notes,
            'updated_at' => $row->updated_at?->format('Y-m-d H:i:s'),
        ], maxOffset: 100000);
    }

    /**
     * The stored-record families, one row per table row in stable id order. Each family names its
     * table, the read ability that already shows that data in the app, and the explicit allow-list
     * of columns with their wire type; no other column is ever selected. `soft` leaves out
     * soft-deleted rows, `parent` leaves out rows whose parent row is soft-deleted, and `asset`
     * keeps only rows whose asset (deleted or not) the reader's company scope can see.
     */
    private const RECORDS = [
        'budget-allocations' => ['table' => 'budget_allocations', 'can' => ['procurement.view'], 'soft' => true,
            'columns' => ['fiscal_year' => 'text', 'area' => 'text', 'amount' => 'decimal', 'source' => 'text', 'description' => 'text', 'effective_date' => 'date', 'created_by' => 'id', 'created_at' => 'time']],
        'capital-request-lines' => ['table' => 'capital_request_lines', 'can' => ['procurement.view'],
            'columns' => ['fiscal_year' => 'text', 'area' => 'text', 'need' => 'text', 'type' => 'text', 'description' => 'text', 'quantity' => 'int', 'unit_cost' => 'decimal', 'preference' => 'text', 'sort_order' => 'int', 'created_at' => 'time']],
        'store-orders' => ['table' => 'store_orders', 'can' => ['view', Requisition::class],
            'columns' => ['user_id' => 'id', 'status' => 'text', 'program' => 'text', 'order_usage' => 'text', 'location_id' => 'id', 'refresh_asset_id' => 'id', 'funding_account' => 'text', 'lease_schedule' => 'text', 'gl_code' => 'text', 'department_po_number' => 'text', 'notes' => 'text', 'decision_notes' => 'text', 'decided_by' => 'id', 'decided_at' => 'time', 'requisition_id' => 'id', 'purchase_order_id' => 'id', 'deployment_wave_id' => 'id', 'vendor_sent_at' => 'time', 'quote_number' => 'text', 'quote_total' => 'decimal', 'quote_expires_at' => 'date', 'quote_received_at' => 'time', 'confirmed_at' => 'time', 'tracking_number' => 'text', 'shipped_at' => 'time', 'arrived_at' => 'time', 'created_at' => 'time']],
        'store-order-items' => ['table' => 'store_order_items', 'can' => ['view', Requisition::class],
            'columns' => ['store_order_id' => 'id', 'catalog_item_id' => 'id', 'description' => 'text', 'vendor_sku' => 'text', 'mfr_part_number' => 'text', 'quantity' => 'int', 'unit_cost' => 'decimal', 'created_at' => 'time']],
        'deployment-types' => ['table' => 'deployment_types', 'can' => ['deployments.view'],
            'columns' => ['name' => 'text', 'slug' => 'text', 'color' => 'text', 'moves_devices' => 'bool', 'sort_order' => 'int', 'active' => 'bool', 'created_at' => 'time']],
        'deployment-stages' => ['table' => 'deployment_stages', 'can' => ['deployments.view'],
            'columns' => ['name' => 'text', 'slug' => 'text', 'color' => 'text', 'sort_order' => 'int', 'is_terminal' => 'bool', 'is_on_hand' => 'bool', 'maps_to_status_id' => 'id', 'active' => 'bool', 'created_at' => 'time']],
        'deployment-waves' => ['table' => 'deployment_waves', 'can' => ['deployments.view'], 'soft' => true,
            'columns' => ['name' => 'text', 'slug' => 'text', 'fiscal_year' => 'text', 'deployment_type_id' => 'id', 'wave_state' => 'text', 'announced_at' => 'time', 'arrival_window_start' => 'date', 'arrival_window_end' => 'date', 'target_start_date' => 'date', 'target_end_date' => 'date', 'location_id' => 'id', 'storage_location_id' => 'id', 'owner_id' => 'id', 'purchase_order_id' => 'id', 'form_key' => 'text', 'color' => 'text', 'sort_order' => 'int', 'notes' => 'text', 'created_by' => 'id', 'created_at' => 'time']],
        'deployment-items' => ['table' => 'deployment_items', 'can' => ['deployments.view'], 'parent' => ['wave_id', 'deployment_waves'], 'asset' => 'asset_id',
            'columns' => ['wave_id' => 'id', 'asset_id' => 'id', 'replaces_asset_id' => 'id', 'order_item_id' => 'id', 'model_id' => 'id', 'stage_id' => 'id', 'group_label' => 'text', 'assigned_user_id' => 'id', 'assigned_tech_id' => 'id', 'storage_location_id' => 'id', 'target_deploy_date' => 'date', 'deployed_at' => 'time', 'notes' => 'text', 'created_at' => 'time']],
        'staff-blackouts' => ['table' => 'staff_blackouts', 'can' => ['deployments.view'],
            'columns' => ['user_id' => 'id', 'source' => 'text', 'start_date' => 'date', 'end_date' => 'date', 'reason' => 'text', 'external_id' => 'text', 'synced_at' => 'time', 'created_at' => 'time']],
        'lease-schedules' => ['table' => 'lease_schedules', 'can' => ['view', Order::class], 'soft' => true,
            'columns' => ['schedule_ref' => 'text', 'lessor' => 'text', 'lease_type' => 'text', 'term_months' => 'int', 'received_at' => 'date', 'expected_acquisition_cost' => 'decimal', 'expected_asset_count' => 'int', 'usage_tag' => 'text', 'lifecycle_stage' => 'text', 'signed_at' => 'time', 'signed_by' => 'id', 'vendor_on_hold' => 'bool', 'contract_id' => 'id', 'notes' => 'text', 'created_by' => 'id', 'created_at' => 'time']],
        'asset-buyouts' => ['table' => 'asset_buyouts', 'can' => ['deployments.view'], 'soft' => true, 'asset' => 'asset_id',
            'columns' => ['asset_id' => 'id', 'lessor_id' => 'id', 'buyer_id' => 'id', 'requested_by' => 'id', 'status' => 'text', 'requested_at' => 'time', 'quote_amount' => 'decimal', 'remaining_rent' => 'decimal', 'quote_total' => 'decimal', 'quoted_at' => 'date', 'buyer_amount' => 'decimal', 'ecu_amount' => 'decimal', 'approved_at' => 'time', 'approved_by' => 'id', 'declined_at' => 'time', 'decline_reason' => 'text', 'invoice_number' => 'text', 'invoice_date' => 'date', 'invoice_due_date' => 'date', 'paid_at' => 'time', 'payment_method' => 'text', 'payment_reference' => 'text', 'completed_at' => 'time', 'notes' => 'text', 'created_at' => 'time']],
        'asset-buyout-quotes' => ['table' => 'asset_buyout_quotes', 'can' => ['deployments.view'], 'parent' => ['asset_buyout_id', 'asset_buyouts'],
            'columns' => ['asset_buyout_id' => 'id', 'quote_amount' => 'decimal', 'remaining_rent' => 'decimal', 'quote_total' => 'decimal', 'quoted_at' => 'date', 'reference' => 'text', 'notes' => 'text', 'recorded_by' => 'id', 'created_at' => 'time']],
        'lease-pickups' => ['table' => 'lease_pickups', 'can' => ['deployments.view'], 'soft' => true,
            'columns' => ['lessor_id' => 'id', 'requested_by' => 'id', 'status' => 'text', 'requested_at' => 'time', 'preferred_dates' => 'text', 'load_number' => 'text', 'scheduled_date' => 'date', 'scheduled_window' => 'text', 'receiving_notified_at' => 'time', 'receiving_notified_for' => 'text', 'picked_up_at' => 'date', 'notes' => 'text', 'created_at' => 'time']],
        'lease-pickup-assets' => ['table' => 'lease_pickup_assets', 'can' => ['deployments.view'], 'parent' => ['lease_pickup_id', 'lease_pickups'], 'asset' => 'asset_id',
            'columns' => ['lease_pickup_id' => 'id', 'asset_id' => 'id', 'created_at' => 'time']],
        'lease-decisions' => ['table' => 'lease_decisions', 'can' => ['view', Order::class], 'soft' => true, 'asset' => 'asset_id',
            'columns' => ['contract_reference' => 'text', 'asset_id' => 'id', 'decision_type' => 'text', 'decision_date' => 'date', 'deferred_to_fy' => 'text', 'amount' => 'decimal', 'status' => 'text', 'notes' => 'text', 'created_by' => 'id', 'created_at' => 'time']],
        'exhibit-email-templates' => ['table' => 'exhibit_email_templates', 'can' => ['view', Order::class],
            'columns' => ['key' => 'text', 'name' => 'text', 'subject' => 'text', 'body' => 'text', 'enabled' => 'bool', 'created_at' => 'time']],
        'maintenances' => ['table' => 'maintenances', 'can' => ['view', Asset::class], 'soft' => true, 'asset' => 'asset_id',
            'columns' => ['asset_id' => 'id', 'supplier_id' => 'id', 'asset_maintenance_type' => 'text', 'name' => 'text', 'url' => 'text', 'is_warranty' => 'bool', 'start_date' => 'date', 'completion_date' => 'date', 'asset_maintenance_time' => 'int', 'notes' => 'text', 'cost' => 'decimal', 'created_by' => 'id', 'created_at' => 'time']],
        'permission-groups' => ['table' => 'permission_groups', 'can' => ['superadmin'],
            'columns' => ['name' => 'text', 'permissions' => 'text', 'notes' => 'text', 'created_at' => 'time']],
        'form-eligibility' => ['table' => 'form_eligibility', 'can' => ['superadmin'],
            'columns' => ['form_slug' => 'text', 'group_id' => 'id', 'created_at' => 'time']],
        'action-logs' => ['table' => 'action_logs', 'can' => ['superadmin'], 'soft' => true, 'maxOffset' => 1000000,
            'columns' => ['created_by' => 'id', 'action_type' => 'text', 'target_id' => 'id', 'target_type' => 'text', 'location_id' => 'id', 'note' => 'text', 'item_type' => 'text', 'item_id' => 'id', 'expected_checkin' => 'date', 'quantity' => 'int', 'log_meta' => 'text', 'action_date' => 'time', 'action_source' => 'text', 'created_at' => 'time']],
    ];

    /** Every family the records route serves; the route constraint and the lookup both use it. */
    public static function recordFamilies(): array
    {
        return [...array_keys(self::RECORDS), 'user-access'];
    }

    /** One stored-record family, exactly its allow-listed columns, typed for the wire. */
    public function records(Request $request, string $family): JsonResponse
    {
        if ($family === 'user-access') {
            return $this->userAccess($request);
        }
        $spec = self::RECORDS[$family] ?? abort(404);
        $this->authorize(...$spec['can']);

        $table = $spec['table'];
        $columns = $spec['columns'];
        $query = DB::table($table)->select(array_map(fn (string $column) => $table.'.'.$column, ['id', ...array_keys($columns)]));
        if ($spec['soft'] ?? false) {
            $query->whereNull($table.'.deleted_at');
        }
        if (isset($spec['parent'])) {
            [$column, $parent] = $spec['parent'];
            $query->whereNotExists(fn (QueryBuilder $gone) => $gone->from($parent)
                ->whereColumn($parent.'.id', $table.'.'.$column)->whereNotNull($parent.'.deleted_at'));
        }
        if (isset($spec['asset'])) {
            $column = $table.'.'.$spec['asset'];
            $query->where(fn (QueryBuilder $visible) => $visible->whereNull($column)
                ->orWhereIn($column, Asset::withTrashed()->select('id')));
        }

        return $this->page($request, $query, function (object $row) use ($columns) {
            $out = ['id' => self::typed('id', $row->id)];
            foreach ($columns as $column => $type) {
                $out[$column] = self::typed($type, $row->{$column});
            }

            return $out;
        }, maxOffset: $spec['maxOffset'] ?? 100000, order: $table.'.id');
    }

    /**
     * Each live user's access as stored: the permission groups they belong to, as ascending group
     * ids, and their own permissions JSON text. No other user field is read.
     */
    private function userAccess(Request $request): JsonResponse
    {
        $this->authorize('superadmin');

        $query = DB::table('users')->whereNull('deleted_at')->select(['id', 'permissions']);
        $response = $this->page($request, $query, fn (object $row) => $row, maxOffset: 100000);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }
        $payload = $response->getData(true);
        $groups = DB::table('users_groups')->whereIn('user_id', array_column($payload['rows'], 'id'))
            ->orderBy('group_id')->get(['user_id', 'group_id'])->groupBy('user_id');
        $payload['rows'] = array_map(function (array $row) use ($groups) {
            $ids = $groups->get($row['id'])?->pluck('group_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            return [
                'id' => self::typed('id', $row['id']),
                'group_ids' => $ids ? implode(',', $ids) : null,
                'permissions' => $row['permissions'],
            ];
        }, $payload['rows']);

        return response()->json($payload, 200, ['Cache-Control' => 'private, no-store']);
    }

    /** A stored value on the wire: ids as positive-integer strings, decimals as their stored text. */
    private static function typed(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'id' => (int) $value > 0 ? (string) (int) $value : null,
            'int' => (int) $value,
            'bool' => (bool) $value,
            'decimal' => (string) $value,
            'date' => substr((string) $value, 0, 10),
            'time' => substr((string) $value, 0, 19),
            default => (string) $value,
        };
    }

    /** Each page is a fresh observation; no cross-page transactional snapshot is asserted. */
    private function page(Request $request, Builder|QueryBuilder $query, callable $project, int $maxOffset = 10000, string $order = 'id'): JsonResponse
    {
        $validation = Validator::make($request->query(), [
            'limit' => 'sometimes|integer|min:1|max:100',
            'offset' => 'sometimes|integer|min:0|max:'.$maxOffset,
        ]);
        if ($validation->fails()) {
            return response()->json(['message' => 'Invalid observation paging.'], 422, ['Cache-Control' => 'private, no-store']);
        }
        $params = $validation->validated();
        $total = (clone $query)->count();
        $rows = $query->orderBy($order)->offset($params['offset'] ?? 0)->limit($params['limit'] ?? 100)
            ->get()->map($project)->values();

        return response()->json(['total' => $total, 'rows' => $rows], 200, ['Cache-Control' => 'private, no-store']);
    }
}
