<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Location;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\CatalogSelfServe;
use App\Services\Deployments\WaveMembership;
use App\Services\Settings\Preferences;
use App\Services\StoreOrderAssetProvisioner;
use App\Services\StoreOrderNotifier;
use App\Services\StoreOrderPlacement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
     * Add a catalog row from a vendor product link.
     *
     * The thing this replaces was a message in a chat thread — "can we add
     * this?" — answered by hand, days later, by the one person who keys the
     * catalog. A one-off HDMI switch does not need a person in the middle,
     * so anybody who may use the store may add from a link and order it in
     * the same visit.
     *
     * Everything the row needs is read off the vendor's page. The price is
     * taken as an estimate, never a quote — see {@see CatalogSelfServe}.
     */
    public function addCatalogItem(Request $request, CatalogSelfServe $selfServe): RedirectResponse
    {
        if ($redirect = $this->storeGate()) {
            return $redirect;
        }

        $validated = $request->validate([
            'url' => 'required|string|max:1024',
        ]);

        $result = $selfServe->addFromLink($validated['url'], auth()->user());

        if ($result['created']) {
            return redirect()->route('store.index')->with(
                'success',
                trans('admin/store/general.catalog_link_added', ['name' => $result['item']->name])
            );
        }

        if ($result['item']) {
            return redirect()->route('store.index')->with(
                'warning',
                trans('admin/store/general.catalog_link_already', ['name' => $result['item']->name])
            );
        }

        return redirect()->route('store.index')
            ->withInput()
            ->with('error', $result['request']->error);
    }

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
        if ($redirect = $this->storeGate()) {
            return $redirect;
        }

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

        // Destinations for a shared cart. Rendered server-side rather than
        // through the select2 endpoint: that one authorizes on
        // view.selectlists, which a tech placing an order need not hold.
        $locations = auth()->user()->canOrderShared()
            ? Location::orderBy('name')->pluck('name', 'id')
            : collect();

        return view('store.index', [
            'payload' => $payload,
            'strings' => $this->storefrontStrings(),
            'locations' => $locations,
            'refreshAsset' => $this->refreshAssetFor($request->query('refresh')),
            // Faculty are never asked to fund their own program laptop.
            'showGlCode' => ! auth()->user()->isFacultyProgramMember(),
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
            'categoryOrder' => CatalogItem::categoryOrder(),
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
     * The machine an early-refresh visit is about. Trusted only when the
     * asset is checked out to the visitor themselves and carries the Staff
     * catalog tag — anything else (a guessed id, a Faculty machine, a
     * colleague's laptop) resolves to no context rather than an error.
     */
    private function refreshAssetFor(mixed $assetId): ?Asset
    {
        return app(StoreOrderPlacement::class)->refreshAssetFor(auth()->user(), $assetId);
    }

    /**
     * Place an order: the cart posted as line items. Quantities and prices
     * are re-read from the catalog server-side — the client only names the
     * items and quantities, it never sets a price.
     */
    public function store(Request $request, StoreOrderPlacement $placement): RedirectResponse
    {
        if ($redirect = $this->storeGate()) {
            return $redirect;
        }

        $validated = $request->validate(StoreOrderPlacement::rules($request));

        $result = $placement->place(auth()->user(), $validated);

        if ($result['error'] === 'order_already_approved') {
            return redirect()->route('store.orders')
                ->with('error', trans('admin/store/general.order_already_approved'));
        }

        if ($result['error'] !== null) {
            return redirect()->route('store.index')
                ->with('error', trans('admin/store/general.'.$result['error']));
        }

        return redirect()->route('store.orders')
            ->with('success', trans('admin/store/general.order_placed'));
    }

    /**
     * The program gate. A faculty member's store journey starts at the
     * intake form — until one is on file for the renewal year, the store
     * (and ordering) redirects them there instead of 403ing into a wall.
     */
    private function storeGate(): ?RedirectResponse
    {
        if (auth()->user()->canUseStore()) {
            return null;
        }

        return redirect()->route('forms.show', 'faculty-program')
            ->with('info', trans('admin/store/general.store_needs_form'));
    }

    /**
     * The requester's own orders, newest first, with where each one sits
     * in the pipeline.
     */
    public function orders(StoreOrderAssetProvisioner $provisioner)
    {
        if ($redirect = $this->storeGate()) {
            return $redirect;
        }

        $orders = StoreOrder::with('items.catalogItem', 'requisition.purchaseOrder', 'refreshAsset')
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
        // buyout; otherwise it goes back at handover. An early-refresh order
        // already names its machine, and the pane's story changes with it —
        // the lease has not ended, the machine is being swapped early.
        $outgoing = null;
        $buyout = false;
        $earlyRefresh = false;

        $openOrder = $orders->getCollection()->first(fn (StoreOrder $order) => $order->isOpen() || $order->status === 'ordered');
        if ($openOrder) {
            $earlyRefresh = (bool) $openOrder->refreshAsset;
            $outgoing = $openOrder->refreshAsset
                ?? $provisioner->outgoingMachine(new StoreOrder(['user_id' => auth()->id()]));
            // A decided buyout, not merely a possible one. `eligible` is the
            // lease-end pipeline noting the machine is buyable; reading it
            // here told people their old laptop was theirs to keep when they
            // had never been asked, which is the opposite of what happens at
            // handover if nobody corrects it.
            $buyout = UserAgreement::where('user_id', auth()->id())
                ->where('agreement_type', 'purchase')
                ->whereIn('lifecycle_stage', array_diff(UserAgreement::OPEN_LIFECYCLE_STAGES, ['eligible']))
                ->exists();
        }

        return view('store.orders', [
            'orders' => $orders,
            'assetsByReference' => $assetsByReference,
            'outgoing' => $outgoing,
            'buyout' => $buyout,
            'earlyRefresh' => $earlyRefresh,
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
