<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Transformers\PurchaseOrdersTransformer;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderProvisioning;
use App\Services\PurchaseOrderVendorDispatch;
use App\Services\SupplierAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrdersController extends Controller
{
    /**
     * Display a listing of purchase orders.
     */
    public function index(FilterRequest $request): array
    {
        $this->authorize('view', Order::class);

        $allowed_columns = [
            'id',
            'po_number',
            'title',
            'fiscal_year',
            'budget',
            'cost_center',
            'status',
            'order_date',
            'created_at',
        ];

        $purchaseOrders = PurchaseOrder::with('supplier', 'company', 'adminuser', 'orders.invoices', 'orders.items')
            ->withCount('orders as orders_count');

        if ($request->filled('filter') || $request->filled('search')) {
            $purchaseOrders->TextSearch($request->input('filter') ? $request->input('filter') : $request->input('search'));
        }

        if ($request->filled('status')) {
            $purchaseOrders->where('status', '=', $request->input('status'));
        }

        if ($request->filled('fiscal_year')) {
            $purchaseOrders->where('fiscal_year', '=', $request->input('fiscal_year'));
        }

        if ($request->filled('supplier_id')) {
            $purchaseOrders->where('supplier_id', '=', $request->input('supplier_id'));
        }

        $offset = ($request->input('offset') > $purchaseOrders->count()) ? $purchaseOrders->count() : app('api_offset_value');
        $limit = app('api_limit_value');

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), $allowed_columns) ? $request->input('sort') : 'created_at';

        $purchaseOrders->orderBy($sort, $order);

        $total = $purchaseOrders->count();
        $purchaseOrders = $purchaseOrders->skip($offset)->take($limit)->get();

        return (new PurchaseOrdersTransformer)->transformPurchaseOrders($purchaseOrders, $total);
    }

    /**
     * Display the specified purchase order.
     *
     * @param  int  $id
     */
    /**
     * Raise the order and provision the devices a purchase order should
     * already have.
     *
     * Promotion does this now, but purchase orders raised before that have
     * nothing underneath: no order, so nothing committed; no assets, so a
     * deployment wave built for them is empty and a shipment has nothing to
     * claim. `dry_run` reports what would be created and writes nothing.
     *
     * An endpoint rather than only a console command because the app runs
     * in a container with no shell to run artisan in — needing to run
     * something and having no way to is the case that calls for an API.
     */
    public function provision(Request $request, $purchaseOrderId): JsonResponse
    {
        $this->authorize('update', PurchaseOrder::class);

        $validated = $request->validate([
            'dry_run' => 'nullable|boolean',
        ]);

        $purchaseOrder = PurchaseOrder::findOrFail($purchaseOrderId);

        $report = app(PurchaseOrderProvisioning::class)
            ->backfill($purchaseOrder, (bool) ($validated['dry_run'] ?? false));

        if (! ($report['ok'] ?? false)) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, $report['error'] ?? 'Backfill failed.'),
                422
            );
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $report,
            trans('admin/orders/general.backfill_done', [
                'po' => $purchaseOrder->po_number,
                'count' => $report['assets_created'] ?? $report['would_create'] ?? 0,
            ])
        ));
    }

    /**
     * Put the order to the vendor, recording the account and quote it goes
     * with. The same send the purchase order page makes, minus the browser:
     * a script that has just filed the PO can place the order in the same
     * breath, and a test send (`test: true`) lets it check the layout first.
     *
     * The gates are the dispatch's, not repeated here — see
     * {@see PurchaseOrderVendorDispatch::send()} for what refuses a send.
     */
    public function sendVendor(Request $request, $purchaseOrderId): JsonResponse
    {
        $this->authorize('update', PurchaseOrder::class);

        $validated = $request->validate([
            'quote_number' => 'nullable|string|max:191',
            'quote_total' => 'nullable|numeric|min:0',
            'quote_expires_at' => 'nullable|date',
            'funding_account' => 'nullable|string|in:'.implode(',', SupplierAccounts::keys()),
            'lease_schedule' => 'nullable|string|max:191',
            'order_cc' => 'nullable|string|max:65535',
            'cc_users' => 'nullable|array',
            'cc_users.*' => 'integer|exists:users,id',
            'test' => 'nullable|boolean',
        ]);

        $purchaseOrder = PurchaseOrder::findOrFail($purchaseOrderId);
        $test = (bool) ($validated['test'] ?? false);

        $result = app(PurchaseOrderVendorDispatch::class)->send($purchaseOrder, $request->user(), $validated, $test);

        if (! $result['sent']) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $result['error']), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', [
            'test' => $test,
            'recipients' => $result['recipients'],
            'purchase_order' => $purchaseOrder->po_number,
            'vendor_stage' => $purchaseOrder->fresh()->vendorStage(),
        ], $test
            ? trans('admin/store/general.vendor_send_test_sent', ['email' => $result['recipients'][0]])
            : trans('admin/store/general.vendor_send_sent', ['emails' => implode(', ', $result['recipients'])])));
    }

    /**
     * Record the vendor's answer, and ours to it — `changes`, `confirm` or
     * `order_number`, the loop their rep set out — or `sent`, a send that was
     * made outside the app, dated with `vendor_sent_at`, so the loop can be
     * joined from there. A `confirm` with
     * `notify_vendor: true` also emails the acceptance to the reps, which is
     * what actually gets the order placed; the order is only stamped accepted
     * once that mail has left.
     */
    public function vendorResponse(Request $request, $purchaseOrderId): JsonResponse
    {
        $this->authorize('update', PurchaseOrder::class);

        $validated = $request->validate([
            'step' => 'required|string|in:sent,changes,confirm,order_number',
            'vendor_sent_at' => 'nullable|date',
            'funding_account' => 'nullable|string|in:'.implode(',', SupplierAccounts::keys()),
            'lease_schedule' => 'nullable|string|max:191',
            'vendor_changes_notes' => 'nullable|string|max:65535',
            'quote_number' => 'nullable|string|max:191',
            'quote_total' => 'nullable|numeric|min:0',
            'quote_expires_at' => 'nullable|date',
            'vendor_order_number' => 'nullable|string|max:191',
            'notify_vendor' => 'nullable|boolean',
        ]);

        $purchaseOrder = PurchaseOrder::findOrFail($purchaseOrderId);

        $result = app(PurchaseOrderVendorDispatch::class)->respond(
            $purchaseOrder,
            $validated['step'],
            $validated,
            (bool) ($validated['notify_vendor'] ?? false)
        );

        if (! $result['ok']) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $result['error']), 422);
        }

        return response()->json(Helper::formatStandardApiResponse('success', [
            'purchase_order' => $purchaseOrder->po_number,
            'vendor_stage' => $result['stage'],
            'recipients' => $result['recipients'],
            'quote_number' => $purchaseOrder->quote_number,
            'quote_total' => $purchaseOrder->quote_total,
            'quote_confirmed_at' => $purchaseOrder->quote_confirmed_at?->toDateTimeString(),
            'vendor_order_number' => $purchaseOrder->vendor_order_number,
        ], $result['message']));
    }

    public function show($id): array
    {
        $this->authorize('view', Order::class);
        $purchaseOrder = PurchaseOrder::with('supplier', 'company', 'adminuser', 'orders.invoices', 'orders.items')->findOrFail($id);

        return (new PurchaseOrdersTransformer)->transformPurchaseOrder($purchaseOrder);
    }
}
