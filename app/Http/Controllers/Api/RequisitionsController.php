<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Transformers\RequisitionsTransformer;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Services\RequisitionBasket;
use App\Services\RequisitionPromotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The PO builder without the browser.
 *
 * Everything the builder page can do — read the catalog and its options,
 * assemble a basket, record the REQM Colleague issued, promote the result to
 * a purchase order with its document — is reachable here, so a requisition
 * can be put together in a terminal or an agent session and picked up in the
 * UI afterwards. The builder form and these endpoints share
 * RequisitionBasket and RequisitionPromotion, so the two paths cannot drift.
 *
 * `options` is the discovery endpoint: call it first and it names every field
 * and every accepted value, rather than requiring the caller to have read
 * this file.
 */
class RequisitionsController extends Controller
{
    /**
     * Every field and option the builder offers: the pickers, their accepted
     * values, and the defaults an omitted field takes.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        $categories = CatalogItem::active()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'fiscal_years' => Helper::fiscalYearOptions(),
            'current_fiscal_year' => Helper::currentFiscalYear(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->values(),
            'companies' => Company::orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->values(),
            'purchase_orders' => PurchaseOrder::orderByDesc('po_number')->get(['id', 'po_number', 'fiscal_year'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'po_number' => $p->po_number, 'fiscal_year' => $p->fiscal_year])->values(),
            'catalog_categories' => $categories,
            'product_types' => CatalogItem::PRODUCT_TYPES,
            'price_types' => CatalogItem::PRICE_TYPES,
            'statuses' => Requisition::STATUSES,
            // The four CDW accounts. One must be set before an order can go to
            // the vendor: it decides the blanket purchase order and the payee.
            'funding_accounts' => StoreOrder::FUNDING_ACCOUNTS,
            'defaults' => [
                'gst_rate' => 0.05,
                'pst_rate' => 0.07,
                'shipping' => 0,
                'unit_of_measure' => 'EA',
                'pst_applicable' => true,
            ],
            'fields' => [
                'requisition' => array_keys(array_filter(
                    RequisitionBasket::rules(),
                    fn ($key) => ! str_starts_with($key, 'items'),
                    ARRAY_FILTER_USE_KEY
                )),
                'item' => ['catalog_item_id', 'description', 'vendor_sku', 'mfr_part_number',
                    'gl_number', 'quantity', 'unit_of_measure', 'unit_cost', 'pst_applicable', 'notes'],
                'promote' => ['purchase_order_id', 'po_number', 'title', 'fiscal_year',
                    'cost_center', 'budget', 'order_date', 'notes', 'document', 'document_notes'],
                'update' => ['requisition_number', 'status', 'notes', 'internal_comments',
                    'printer_comments', 'quote_number', 'quote_total', 'quote_expires_at',
                    'funding_account', 'lease_schedule'],
            ],
            'rules' => RequisitionBasket::rules(),
        ], null));
    }

    /**
     * The catalog as the builder sees it, with the same filters the page
     * offers, so a caller can find a SKU without pulling the whole shelf.
     */
    public function catalog(Request $request): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        $items = CatalogItem::with('supplier', 'manufacturer')
            ->active()
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->query('supplier_id')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->when($request->filled('product_type'), fn ($q) => $q->where('product_type', $request->query('product_type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->query('search').'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('vendor_sku', 'like', $term)
                    ->orWhere('mfr_part_number', 'like', $term)
                    ->orWhere('subcategory', 'like', $term));
            })
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $rows = $items->map(fn (CatalogItem $item) => [
            'id' => (int) $item->id,
            'name' => $item->name,
            'category' => $item->category,
            'subcategory' => $item->subcategory,
            'product_type' => $item->product_type,
            'vendor_sku' => $item->vendor_sku,
            'mfr_part_number' => $item->mfr_part_number,
            'unit_cost' => round($item->effectiveCost(), 2),
            'is_estimate' => $item->isEstimate(),
            'is_expired' => $item->isExpired(),
            'supplier' => $item->supplier?->name,
            'source' => $item->source,
        ])->values();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'total' => $rows->count(),
            'rows' => $rows,
        ], null));
    }

    /**
     * The requisition list. Serves both a plain API caller and the
     * bootstrap-table on the requisitions page and the procurement hub, so
     * it honours the table's paging, sorting and search parameters as well
     * as the domain filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        $allowedColumns = [
            'id', 'title', 'status', 'requisition_number', 'fiscal_year',
            'cost_center', 'needed_by', 'created_at',
        ];

        $query = Requisition::with('items.catalogItem', 'supplier', 'company', 'purchaseOrder', 'adminuser')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('fiscal_year'), fn ($q) => $q->where('fiscal_year', $request->input('fiscal_year')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->input('supplier_id')))
            ->when($request->filled('requisition_number'), fn ($q) => $q->where('requisition_number', $request->input('requisition_number')));

        $term = $request->input('filter') ?: $request->input('search');
        if ($term) {
            $query->where(function ($inner) use ($term) {
                $like = '%'.$term.'%';
                $inner->where('title', 'like', $like)
                    ->orWhere('requisition_number', 'like', $like)
                    ->orWhere('fiscal_year', 'like', $like)
                    ->orWhere('cost_center', 'like', $like);
            });
        }

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), $allowedColumns, true) ? $request->input('sort') : 'created_at';
        $query->orderBy($sort, $order);

        $total = $query->count();

        // app('api_limit_value') defaults to the configured page size, so a
        // caller that asks for nothing still gets a bounded response.
        $requisitions = $query->skip(app('api_offset_value'))->take(app('api_limit_value'))->get();

        return response()->json(
            (new RequisitionsTransformer)->transformRequisitions($requisitions, $total)
        );
    }

    public function show(Requisition $requisition): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        $requisition->load('items.catalogItem', 'supplier', 'company', 'purchaseOrder', 'adminuser');

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new RequisitionsTransformer)->transformRequisition($requisition),
            null
        ));
    }

    /**
     * Build a basket, or replace the lines on a draft by passing its id as
     * `requisition_id`.
     */
    public function store(Request $request, RequisitionBasket $basket): JsonResponse
    {
        $this->authorize('create', Requisition::class);

        $validated = $request->validate(RequisitionBasket::rules());

        try {
            $requisition = $basket->save($validated);
        } catch (\RuntimeException $e) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->getMessage()), 422);
        }

        $requisition->load('items.catalogItem', 'supplier', 'company', 'purchaseOrder', 'adminuser');

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new RequisitionsTransformer)->transformRequisition($requisition),
            trans('admin/purchase-orders/general.requisition_saved')
        ));
    }

    /**
     * Record what came back from Colleague. Status derives from the
     * paperwork unless it is set explicitly, matching the web form.
     */
    public function update(Request $request, Requisition $requisition): JsonResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'requisition_number' => 'sometimes|nullable|string|max:191',
            'status' => 'sometimes|nullable|string|in:'.implode(',', Requisition::STATUSES),
            'notes' => 'sometimes|nullable|string|max:65535',
            'internal_comments' => 'sometimes|nullable|string|max:65535',
            'printer_comments' => 'sometimes|nullable|string|max:65535',
            // The quote the vendor sent back, and the account the order is
            // placed against. Settable here because the whole point of these
            // endpoints is that a requisition can be driven without a browser
            // — and recording a quote is what reopens the basket for
            // repricing, which is a scripted job when a quote covers 40 lines.
            'quote_number' => 'sometimes|nullable|string|max:191',
            'quote_total' => 'sometimes|nullable|numeric|min:0',
            'quote_expires_at' => 'sometimes|nullable|date',
            'funding_account' => 'sometimes|nullable|string|in:'.implode(',', StoreOrder::FUNDING_ACCOUNTS),
            'lease_schedule' => 'sometimes|nullable|string|max:191',
        ]);

        $hadRequisitionNumber = (bool) $requisition->requisition_number;

        foreach ([
            'requisition_number', 'notes', 'internal_comments', 'printer_comments',
            'quote_number', 'quote_total', 'quote_expires_at', 'funding_account', 'lease_schedule',
        ] as $field) {
            if ($request->has($field)) {
                $requisition->{$field} = $validated[$field] ?? null;
            }
        }

        if (! empty($validated['status'])) {
            $requisition->status = $validated['status'];
        } elseif ($requisition->requisition_number && ! $requisition->purchase_order_id) {
            $requisition->status = 'requisitioned';
        }

        if ($requisition->status === 'submitted' && ! $requisition->submitted_at) {
            $requisition->submitted_at = now();
        }

        if ($requisition->requisition_number && ! $hadRequisitionNumber) {
            $requisition->requisitioned_at = now();
        }

        if (! $requisition->save()) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, $requisition->getErrors()),
                422
            );
        }

        $requisition->load('items.catalogItem', 'supplier', 'company', 'purchaseOrder', 'adminuser');

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            (new RequisitionsTransformer)->transformRequisition($requisition),
            trans('admin/purchase-orders/general.requisition_updated')
        ));
    }

    /**
     * Promote to a purchase order. The PDF is required here for the same
     * reason it is on the form — see RequisitionPromotion — so this endpoint
     * takes multipart, not JSON, unless it is linking a purchase order that
     * already carries its document.
     */
    public function promote(Request $request, Requisition $requisition, RequisitionPromotion $promotion): JsonResponse
    {
        $this->authorize('update', Requisition::class);

        $requisition->load('items');

        $existing = $request->filled('purchase_order_id')
            ? PurchaseOrder::find($request->input('purchase_order_id'))
            : null;

        $documentRule = ($existing && $promotion->hasDocument($existing)) ? 'nullable' : 'required';

        $validated = $request->validate([
            'purchase_order_id' => 'nullable|integer|exists:purchase_orders,id',
            'po_number' => 'required_without:purchase_order_id|nullable|string|max:191',
            'title' => 'nullable|string|max:191',
            'fiscal_year' => 'nullable|string|max:191',
            'cost_center' => 'nullable|string|max:191',
            'budget' => 'nullable|numeric|min:0',
            'order_date' => 'nullable|date',
            'notes' => 'nullable|string|max:65535',
            'document' => $documentRule.'|file|mimes:pdf|max:'.Helper::file_upload_max_size(),
            'document_notes' => 'nullable|string|max:191',
        ], [
            'document.required' => trans('admin/purchase-orders/general.promote_document_required'),
        ]);

        try {
            $purchaseOrder = $promotion->promote($requisition, $validated, $request->file('document'));
        } catch (\RuntimeException $e) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->getMessage()), 422);
        }

        $requisition->load('items.catalogItem', 'supplier', 'company', 'purchaseOrder', 'adminuser');

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            [
                'requisition' => (new RequisitionsTransformer)->transformRequisition($requisition),
                'purchase_order' => [
                    'id' => (int) $purchaseOrder->id,
                    'po_number' => $purchaseOrder->po_number,
                    'fiscal_year' => $purchaseOrder->fiscal_year,
                    'budget' => $purchaseOrder->budget !== null ? (float) $purchaseOrder->budget : null,
                    'status' => $purchaseOrder->status,
                ],
            ],
            trans('admin/purchase-orders/general.promote_success', ['po' => $purchaseOrder->po_number])
        ));
    }

    public function destroy(Requisition $requisition): JsonResponse
    {
        $this->authorize('delete', Requisition::class);

        if ($requisition->status === 'ordered') {
            return response()->json(Helper::formatStandardApiResponse(
                'error',
                null,
                trans('admin/purchase-orders/general.requisition_locked')
            ), 422);
        }

        $requisition->delete();

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            ['id' => (int) $requisition->id],
            trans('admin/purchase-orders/general.requisition_deleted')
        ));
    }
}
