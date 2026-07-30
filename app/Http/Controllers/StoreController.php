<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\UserAgreement;
use App\Services\StoreOrderAssetProvisioner;
use App\Services\StoreOrderNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * The storefront: an Apple-style walk from product family to a fully
     * specified configuration. The server ships every shelf item with its
     * structured specs as one JSON payload; the browser does the
     * configurator narrowing client-side, exactly like the PO builder
     * keeps its basket client-side until one POST.
     *
     * Accessories ride along too, rendered as their own separated
     * section so pencils and cables never clutter the device grid.
     */
    public function index(Request $request)
    {
        $items = CatalogItem::with('model.manufacturer')
            ->inStore()
            ->orderBy('store_sort')
            ->orderBy('name')
            ->get();

        $payload = $items->map(fn (CatalogItem $item) => [
            'id' => $item->id,
            'name' => $item->name,
            'category' => $item->category,
            'platform' => $item->platform(),
            'family' => $item->family ?: $item->name,
            'screen_size' => $item->screen_size,
            'chip' => $item->chip,
            'spec_cpu' => $item->spec_cpu,
            'spec_gpu' => $item->spec_gpu,
            'spec_npu' => $item->spec_npu,
            'ram_gb' => $item->ram_gb,
            'storage' => $item->storage,
            'color' => $item->color,
            'display_finish' => $item->display_finish,
            'extras' => $item->extras,
            'sku' => $item->mfr_part_number ?: $item->vendor_sku,
            'price' => round($item->effectiveCost()),
            'estimate' => $item->isEstimate(),
            'image' => $item->storeImageUrl(),
            'specs' => $item->specList(),
        ])->values();

        return view('store.index', [
            'payload' => $payload,
            'strings' => $this->storefrontStrings(),
            'openOrderCount' => StoreOrder::where('user_id', auth()->id())
                ->whereIn('status', ['pending', 'approved'])
                ->count(),
        ]);
    }

    /**
     * Every string the client-side storefront renders, so the JS stays
     * translation-clean.
     *
     * @return array<string, mixed>
     */
    private function storefrontStrings(): array
    {
        $step = fn (string $key) => [
            'title' => trans('admin/store/general.step_'.$key.'_title'),
            'sub' => trans('admin/store/general.step_'.$key.'_sub'),
        ];

        return [
            'from' => trans('admin/store/general.from_price'),
            'back' => trans('admin/store/general.back_to_products'),
            'add' => trans('admin/store/general.add_to_order'),
            'approx' => trans('admin/store/general.approx_price'),
            'included' => trans('admin/store/general.included'),
            'quantity' => trans('admin/store/general.quantity'),
            'remove' => trans('admin/store/general.remove'),
            'summaryTitle' => trans('admin/store/general.summary_title'),
            'summarySub' => trans('admin/store/general.summary_sub'),
            'standardConfig' => trans('admin/store/general.standard_config'),
            'unifiedMemory' => trans('admin/store/general.unified_memory'),
            'ssdStorage' => trans('admin/store/general.ssd_storage'),
            'displayStandard' => trans('admin/store/general.display_standard'),
            'displayNano' => trans('admin/store/general.display_nano'),
            'allProducts' => trans('admin/store/general.all_categories'),
            'storeEmpty' => trans('admin/store/general.store_empty'),
            'otherHeading' => trans('admin/store/general.other_heading'),
            'steps' => [
                'screen_size' => $step('size'),
                'chip' => $step('chip'),
                'color' => $step('color'),
                'ram_gb' => $step('ram'),
                'storage' => $step('storage'),
                'display_finish' => $step('display'),
                'extras' => $step('extras'),
            ],
        ];
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

        // Orders from faculty ride the faculty laptop program — its
        // intake and agreement flow picks them up from the queue.
        $isFaculty = auth()->user()->groups()
            ->where('permission_groups.name', 'like', '%faculty%')
            ->exists();

        $order = DB::transaction(function () use ($validated, $isFaculty) {
            $order = StoreOrder::create([
                'user_id' => auth()->id(),
                'status' => 'pending',
                'program' => $isFaculty ? 'faculty' : null,
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

        // The devices on this order become real assets now — A-number, model,
        // requester's name, no serial until CDW ships. Failure here must not
        // undo a placed order, so it logs rather than throws; the queue makes
        // a missing record visible.
        try {
            app(StoreOrderAssetProvisioner::class)->provision($order->load('items.catalogItem', 'user'));
        } catch (\Throwable $e) {
            Log::error('Asset provisioning failed for order '.$order->id.': '.$e->getMessage());
        }

        StoreOrderNotifier::requester($order->load('items', 'user'), 'requested');

        return redirect()->route('store.orders')
            ->with('success', trans('admin/store/general.order_placed'));
    }

    /**
     * The requester's own orders, newest first, with where each one sits
     * in the pipeline.
     */
    public function orders(StoreOrderAssetProvisioner $provisioner)
    {
        $orders = StoreOrder::with('items.catalogItem', 'requisition.purchaseOrder')
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->paginate(25);

        // One query for every order's provisioned assets, keyed by the
        // reference they were stamped with at submission.
        $assetsByReference = Asset::whereIn(
            'order_number',
            $orders->getCollection()->map(fn (StoreOrder $order) => $order->reference())
        )->with('model', 'status')->get()->groupBy('order_number');

        // The machine being replaced, and what the intake form said should
        // happen to it. An open purchase-type agreement means they chose the
        // buyout; otherwise it goes back at handover.
        $outgoing = null;
        $buyout = false;

        if ($orders->getCollection()->contains(fn (StoreOrder $order) => $order->isOpen() || $order->status === 'ordered')) {
            $outgoing = $provisioner->outgoingMachine(new StoreOrder(['user_id' => auth()->id()]));
            $buyout = UserAgreement::where('user_id', auth()->id())
                ->where('agreement_type', 'purchase')
                ->whereIn('lifecycle_stage', UserAgreement::OPEN_LIFECYCLE_STAGES)
                ->exists();
        }

        return view('store.orders', [
            'orders' => $orders,
            'assetsByReference' => $assetsByReference,
            'outgoing' => $outgoing,
            'buyout' => $buyout,
        ]);
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

        // The assets provisioned at submission will never arrive; release
        // them while they are still serial-less and unassigned.
        app(StoreOrderAssetProvisioner::class)->release($order);

        return redirect()->route('store.orders')
            ->with('success', trans('admin/store/general.order_cancelled'));
    }
}
