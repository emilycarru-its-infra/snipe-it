<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The internal store — the CDW-eStore replacement.
 *
 * Every SSO user can browse and place an order; no procurement permission
 * is involved on this side. An order is a selection, not a purchase: it
 * lands in the /procurement approval queue, and everything after approval
 * rides the existing requisition → PO → vendor-order chain.
 */
class StoreController extends Controller
{
    /**
     * The storefront: category sidebar plus a product grid, mirroring the
     * CDW eStore layout the fleet already knows.
     */
    public function index(Request $request)
    {
        $items = CatalogItem::with('model')
            ->inStore()
            ->orderBy('store_sort')
            ->orderBy('name')
            ->get();

        $categories = $items->pluck('category')->filter()->unique()->sort()->values();
        $selected = $request->query('category');

        if ($selected && ! $categories->contains($selected)) {
            $selected = null;
        }

        return view('store.index', [
            'items' => $selected ? $items->where('category', $selected)->values() : $items,
            'categories' => $categories,
            'selectedCategory' => $selected,
            'openOrderCount' => StoreOrder::where('user_id', auth()->id())
                ->whereIn('status', ['pending', 'approved'])
                ->count(),
        ]);
    }

    /**
     * Place an order: the cart posted as line items. Quantities and prices
     * are re-read from the catalog server-side — the client only names the
     * items and quantities, it never sets a price.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.catalog_item_id' => 'required|integer|exists:catalog_items,id',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'notes' => 'nullable|string|max:65535',
        ]);

        $order = DB::transaction(function () use ($validated) {
            $order = StoreOrder::create([
                'user_id' => auth()->id(),
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $line) {
                $item = CatalogItem::inStore()->find($line['catalog_item_id']);

                // A row hidden between page load and submit is dropped, not
                // errored — the rest of the order is still what they meant.
                if (! $item) {
                    continue;
                }

                StoreOrderItem::create([
                    'store_order_id' => $order->id,
                    'catalog_item_id' => $item->id,
                    'description' => $item->name,
                    'vendor_sku' => $item->vendor_sku,
                    'mfr_part_number' => $item->mfr_part_number,
                    'quantity' => (int) $line['quantity'],
                    'unit_cost' => round($item->effectiveCost(), 2),
                ]);
            }

            return $order;
        });

        if ($order->items()->count() === 0) {
            $order->delete();

            return redirect()->route('store.index')
                ->with('error', trans('admin/store/general.order_empty'));
        }

        return redirect()->route('store.orders')
            ->with('success', trans('admin/store/general.order_placed'));
    }

    /**
     * The requester's own orders, newest first, with where each one sits
     * in the pipeline.
     */
    public function orders()
    {
        $orders = StoreOrder::with('items', 'requisition.purchaseOrder')
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('store.orders', ['orders' => $orders]);
    }

    /**
     * Withdraw an order that hasn't been reviewed yet. Once procurement
     * has touched it, the conversation happens there instead.
     */
    public function cancel(StoreOrder $order): RedirectResponse
    {
        abort_unless($order->user_id === auth()->id(), 403);

        if ($order->status !== 'pending') {
            return redirect()->route('store.orders')
                ->with('error', trans('admin/store/general.order_not_cancellable'));
        }

        $order->update(['status' => 'cancelled']);

        return redirect()->route('store.orders')
            ->with('success', trans('admin/store/general.order_cancelled'));
    }
}
