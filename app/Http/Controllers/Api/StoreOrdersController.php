<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Requisition;
use App\Models\StoreOrder;
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
