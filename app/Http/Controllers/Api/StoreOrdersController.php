<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Requisition;
use App\Models\PurchaseOrder;
use App\Models\StoreApprover;
use App\Models\StoreOrder;
use App\Services\StoreOrderDecision;
use App\Services\StoreOrderNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The webhook-facing side of a store order's lifecycle: when
 * cdw-orders-listener receives the vendor's shipment event, it lands the
 * tracking number, serials and status here, which updates what the
 * requester sees and sends them the shipped/arrived email.
 */
class StoreOrdersController extends Controller
{
    /**
     * The approval queue, readable without a browser session. The web queue
     * renders the same set; this is the shape a script or an agent needs to
     * answer "what is waiting on a decision, and for how much" without
     * driving the page.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('view', Requisition::class);

        $validated = $request->validate([
            'status' => 'nullable|string|in:'.implode(',', StoreOrder::STATUSES),
            'limit' => 'nullable|integer|min:1|max:500',
            'offset' => 'nullable|integer|min:0',
        ]);

        $query = StoreOrder::with(['user', 'items', 'purchaseOrder'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('created_at');

        $total = $query->count();
        $orders = $query->skip($validated['offset'] ?? 0)
            ->take($validated['limit'] ?? 100)
            ->get();

        return response()->json([
            'total' => $total,
            'rows' => $orders->map(fn (StoreOrder $order) => $this->row($order))->all(),
        ]);
    }

    /**
     * Approve or decline one order — the same decision the queue's buttons
     * make, including releasing a declined order's provisioned assets and
     * telling the requester either way. Kept beside the web controller
     * deliberately: two doors onto one decision must not drift, so both
     * call the same service.
     */
    public function decide(Request $request, StoreOrder $order): JsonResponse
    {
        abort_unless(StoreApprover::allows(auth()->user()), 403);

        $validated = $request->validate([
            'decision' => 'required|string|in:approved,declined',
            'decision_notes' => 'nullable|string|max:65535',
            'funding_account' => 'nullable|string|in:'.implode(',', StoreOrder::FUNDING_ACCOUNTS),
            'lease_schedule' => 'nullable|string|max:32',
        ]);

        if ($order->status !== 'pending') {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('admin/store/general.queue_already_decided')),
                422
            );
        }

        app(StoreOrderDecision::class)->decide($order, $validated);

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $this->row($order->fresh(['user', 'items', 'purchaseOrder'])),
            trans('admin/store/general.queue_decided_'.$validated['decision'])
        ));
    }

    /**
     * Point approved orders at the purchase order whose budget already
     * covers them.
     *
     * The old route to a budget was to pull orders into a fresh requisition,
     * which minted a second purchase order for devices an existing one had
     * been raised to buy. Naming the PO instead lets the request stand
     * against money that is already approved, and lets the PO show what is
     * being asked of it.
     */
    public function attach(Request $request): JsonResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'orders' => 'required|array|min:1',
            'orders.*' => 'integer|exists:store_orders,id',
            'purchase_order_id' => 'required|integer|exists:purchase_orders,id',
        ]);

        $purchaseOrder = PurchaseOrder::findOrFail($validated['purchase_order_id']);

        $orders = StoreOrder::with('items')
            ->whereIn('id', $validated['orders'])
            ->whereIn('status', ['approved', 'ordered'])
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('admin/store/general.queue_nothing_approved')),
                422
            );
        }

        StoreOrder::whereIn('id', $orders->pluck('id'))
            ->update(['purchase_order_id' => $purchaseOrder->id]);

        $purchaseOrder->load('storeOrders.items');

        return response()->json(Helper::formatStandardApiResponse('success', [
            'purchase_order' => $purchaseOrder->po_number,
            'budget' => (float) $purchaseOrder->budget,
            'attached' => $orders->count(),
            'requested_total' => $purchaseOrder->requestedTotal(),
            'orders' => $orders->map(fn (StoreOrder $order) => $order->reference())->all(),
        ], trans('admin/store/general.queue_attached', [
            'count' => $orders->count(),
            'po' => $purchaseOrder->po_number,
        ])));
    }

    /**
     * One order as every reader of this API wants it: who asked, what for,
     * what it costs, and whether it can move.
     *
     * @return array<string, mixed>
     */
    private function row(StoreOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference(),
            'status' => $order->status,
            'requester' => [
                'id' => $order->user_id,
                'name' => $order->user?->present()->fullName,
                'email' => $order->user?->email,
            ],
            'funding_account' => $order->funding_account,
            'lease_schedule' => $order->lease_schedule,
            'ready_for_vendor' => $order->readyForVendor(),
            'notes' => $order->notes,
            'decision_notes' => $order->decision_notes,
            'purchase_order' => $order->purchaseOrder?->po_number,
            'total' => $order->total(),
            'created_at' => $order->created_at?->toDateTimeString(),
            'vendor_sent_at' => $order->vendor_sent_at?->toDateTimeString(),
            'items' => $order->items->map(fn ($line) => [
                'description' => $line->description,
                'quantity' => (int) $line->quantity,
                'unit_cost' => (float) $line->unit_cost,
                'line_total' => $line->lineTotal(),
            ])->all(),
        ];
    }

    public function shipment(Request $request, StoreOrder $order): JsonResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'status' => 'required|string|in:shipped,arrived',
            'tracking_number' => 'nullable|string|max:191',
            'serials' => 'nullable|array',
            'serials.*' => 'string|max:191',
        ]);

        if (! in_array($order->status, ['approved', 'ordered'], true)) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('admin/store/general.shipment_wrong_state', ['status' => $order->status])),
                422
            );
        }

        $updates = ['status' => 'ordered'];

        if (! empty($validated['tracking_number'])) {
            $updates['tracking_number'] = $validated['tracking_number'];
        }

        if ($validated['status'] === 'shipped') {
            $updates['shipped_at'] = $order->shipped_at ?? now();
        } else {
            $updates['shipped_at'] = $order->shipped_at ?? now();
            $updates['arrived_at'] = $order->arrived_at ?? now();
        }

        $order->update($updates);

        StoreOrderNotifier::requester($order->load('items', 'user'), $validated['status'], [
            'tracking' => $order->tracking_number,
            'serials' => $validated['serials'] ?? [],
        ]);

        return response()->json(Helper::formatStandardApiResponse('success', [
            'id' => $order->id,
            'display_status' => $order->displayStatus(),
        ], null));
    }
}
