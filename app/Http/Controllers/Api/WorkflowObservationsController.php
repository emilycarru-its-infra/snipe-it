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
use App\Models\ExhibitProject;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\PurchaseOrder;
use App\Models\UserAgreement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Read-only source facts; no recipient lookup, financial policy or workflow transition. */
class WorkflowObservationsController extends Controller
{
    public function catalogItems(Request $request): JsonResponse
    {
        $this->authorize('view', CatalogItem::class);

        $fields = ['id', 'name', 'category', 'supplier_id', 'manufacturer_id', 'model_id',
            'vendor_sku', 'mfr_part_number', 'unit_cost', 'estimated_cost', 'currency',
            'price_type', 'is_active', 'show_in_store', 'self_serve', 'store_sort'];

        return $this->page($request, CatalogItem::query()->active()->select($fields), function (CatalogItem $row) use ($fields) {
            $facts = $row->only($fields);
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

    /** Each page is a fresh observation; no cross-page transactional snapshot is asserted. */
    private function page(Request $request, Builder $query, callable $project, int $maxOffset = 10000): JsonResponse
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
        $rows = $query->orderBy('id')->offset($params['offset'] ?? 0)->limit($params['limit'] ?? 100)
            ->get()->map($project)->values();

        return response()->json(['total' => $total, 'rows' => $rows], 200, ['Cache-Control' => 'private, no-store']);
    }
}
