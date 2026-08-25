<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\CsiSchedule;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StoreApprover;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Services\SupplierAccounts;
use App\Services\StoreOrderDecision;
use App\Services\StoreVendorOrderDispatch;
use App\Services\StoreOrderNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The /procurement operational hub.
 *
 * Everything that runs procurement day to day lives under here — the store
 * approval queue, storefront management, the catalog, the PO builder and
 * requisitions — while /reports/procurement stays what its name says:
 * reporting. Access rides the same `orders` permission the rest of the
 * purchasing tooling uses.
 */
class ProcurementController extends Controller
{
    /**
     * The hub. Queue depth and catalog health at the top, then every
     * procurement table on the page behind tabs.
     *
     * Each of those tables still has its own route in the sidebar — this is
     * not a replacement for them. It is the answer to "where do I go to do
     * procurement", which previously was six different places.
     *
     * The queue arrives server-rendered because approving and declining are
     * forms per order; the rest are ajax datatables that fetch when their
     * tab is opened.
     */
    /**
     * The hub merged into the module board: /procurement renders the
     * pipeline, hub tiles and tabs on one page. This route survives only
     * as a compat redirect for old links.
     */
    public function index(Request $request)
    {
        // Same gate as the board this redirects to — an unauthorized visitor
        // gets the 403 here rather than a bounce onto a page that 403s.
        $this->authorize('procurement.view');

        return redirect()->route('reports.procurement', array_filter([
            'status' => $request->query('status'),
        ]));
    }

    /**
     * The approval queue: every store order that still needs a decision,
     * plus recently decided ones for context.
     */
    public function queue(Request $request)
    {
        // Listed approvers get in even without the orders permission —
        // they cannot decide what they cannot see.
        if (! StoreApprover::allows(auth()->user())) {
            $this->authorize('view', Requisition::class);
        }

        // No filter means everything. Defaulting to pending hid the rest of
        // the page behind a closed dropdown — an approved order was still
        // there, but you had to already know to go looking for it.
        $status = $request->query('status', 'all');
        if (! in_array($status, StoreOrder::STATUSES, true) && $status !== 'all') {
            $status = 'all';
        }

        $orders = StoreOrder::with('items.catalogItem.supplier', 'user.department', 'decidedBy', 'requisition.purchaseOrder', 'refreshAsset')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('created_at')
            ->paginate(50)
            ->withQueryString();

        // One grouped count for the pills, so the page says how much is
        // waiting before anyone clicks anything.
        $counts = StoreOrder::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Cards by default — the decision needs the whole order in front of
        // you. The table is for the other job this page does: scanning what
        // has already happened, where one row per order beats one card.
        $view = $request->query('view') === 'table' ? 'table' : 'cards';

        return view('procurement.queue', [
            'orders' => $orders,
            'selectedStatus' => $status,
            'selectedView' => $view,
            'statuses' => StoreOrder::STATUSES,
            'statusCounts' => $counts->put('all', $counts->sum())->all(),
            'fundingAccounts' => StoreOrder::fundingAccounts(),
            'leaseSchedules' => CsiSchedule::openScheduleNames(),
            // What is actually waiting on somebody, in count and in money, so
            // the page opens with the size of the job rather than making you
            // add up fourteen cards to find it.
            'pendingValue' => StoreOrder::where('status', 'pending')->with('items')->get()
                ->sum(fn (StoreOrder $order) => $order->total()),
            'clearableCount' => $this->clearable()->count(),
        ]);
    }

    /**
     * Orders that may be thrown away: declined or cancelled, and not already
     * pulled onto a requisition.
     *
     * Both statuses are dead ends — declining already released whatever the
     * order had provisioned (see decide()), and a cancelled one never got
     * that far — so nothing downstream points at these. The requisition guard
     * is belt and braces: an order that reached a requisition is part of a
     * paper trail regardless of what its status says.
     */
    private function clearable()
    {
        return StoreOrder::whereIn('status', ['declined', 'cancelled'])->whereNull('requisition_id');
    }

    /** Throw away one dead request. */
    public function destroyOrder(StoreOrder $order): RedirectResponse
    {
        abort_unless(StoreApprover::allows(auth()->user()), 403);

        if (! in_array($order->status, ['declined', 'cancelled'], true) || $order->requisition_id) {
            return redirect()->back()->with('error', trans('admin/store/general.queue_not_clearable'));
        }

        $order->items()->delete();
        $order->delete();

        return redirect()->back()->with('success', trans('admin/store/general.queue_cleared_one'));
    }

    /**
     * Clear the lot. The queue accumulates declined and cancelled requests
     * that no one will ever act on again, and they crowd out the ones that
     * still need a decision.
     */
    public function clearDecided(): RedirectResponse
    {
        abort_unless(StoreApprover::allows(auth()->user()), 403);

        $ids = $this->clearable()->pluck('id');

        if ($ids->isEmpty()) {
            return redirect()->back()->with('error', trans('admin/store/general.queue_nothing_to_clear'));
        }

        StoreOrderItem::whereIn('store_order_id', $ids)->delete();
        StoreOrder::whereIn('id', $ids)->delete();

        return redirect()->route('procurement.approvals')
            ->with('success', trans('admin/store/general.queue_cleared', ['count' => $ids->count()]));
    }

    /**
     * Decide one order: approve or decline, with an optional note that the
     * requester sees.
     */
    public function decide(Request $request, StoreOrder $order): RedirectResponse
    {
        // The configurable approver list outranks the orders permission
        // the moment anyone is on it; empty list = permission as usual.
        abort_unless(StoreApprover::allows(auth()->user()), 403);

        $validated = $request->validate([
            'decision' => 'required|string|in:approved,declined',
            'decision_notes' => 'nullable|string|max:65535',
            'funding_account' => 'nullable|string|in:'.implode(',', StoreOrder::fundingAccounts()),
            'lease_schedule' => 'nullable|string|max:32',
        ]);

        if ($order->status !== 'pending') {
            return redirect()->route('procurement.approvals')
                ->with('error', trans('admin/store/general.queue_already_decided'));
        }

        app(StoreOrderDecision::class)->decide($order, $validated);

        return redirect()->route('procurement.approvals')
            ->with('success', trans('admin/store/general.queue_decided_'.$validated['decision']));
    }

    /**
     * Email approved orders to the vendor's reps as one order request —
     * a single order from its own button, or several batched together
     * from the queue's checkboxes.
     *
     * With `test`, the exact same email goes only to the person clicking
     * — so the layout can be confirmed before a real rep ever sees one —
     * and nothing changes on any order. A real send flips every order to
     * `ordered`, stamps vendor_sent_at, and tells each requester.
     */
    public function sendVendorOrders(Request $request): RedirectResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'orders' => 'required|array|min:1',
            'orders.*' => 'integer|exists:store_orders,id',
        ]);

        $test = $request->boolean('test');

        $orders = StoreOrder::with('items.catalogItem.supplier', 'user')
            ->whereIn('id', $validated['orders'])
            ->where('status', 'approved')
            ->orderBy('id')
            ->get();

        $result = app(StoreVendorOrderDispatch::class)->send($orders, auth()->user(), $test);

        if (! $result['sent']) {
            return redirect()->route('procurement.approvals', ['status' => 'approved'])
                ->with('error', $result['error']);
        }

        if ($test) {
            return redirect()->route('procurement.approvals', ['status' => 'approved'])
                ->with('success', trans('admin/store/general.vendor_send_test_sent', ['email' => $result['recipients'][0]]));
        }

        return redirect()->route('procurement.approvals', ['status' => 'approved'])
            ->with('success', trans('admin/store/general.vendor_send_sent', ['emails' => implode(', ', $result['recipients'])]));
    }

    /**
     * Record the quote CDW sends back against an order request, and — on a
     * second pass — confirm it, which is the moment the order is actually
     * placed.
     *
     * Two steps rather than one because they are two different people's
     * decisions. CDW's quote arrives with its own number, its own total and
     * an expiry, and often with substitutions for parts that were
     * discontinued since our price list; confirming it is us accepting
     * those. Collapsing them would record an order as placed at the moment
     * the vendor asked us a question.
     */
    public function recordQuote(Request $request, StoreOrder $order): RedirectResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'quote_number' => 'nullable|string|max:191',
            'quote_total' => 'nullable|numeric|min:0',
            'quote_expires_at' => 'nullable|date',
            'confirm' => 'nullable|boolean',
        ]);

        // Nothing to quote against until the request has actually gone out.
        if ($order->vendor_sent_at === null) {
            return redirect()->route('procurement.approvals', ['status' => 'ordered'])
                ->with('error', trans('admin/store/general.quote_wrong_state'));
        }

        $updates = [];

        foreach (['quote_number', 'quote_total', 'quote_expires_at'] as $field) {
            if ($request->filled($field)) {
                $updates[$field] = $validated[$field];
            }
        }

        if ($updates !== [] && $order->quote_received_at === null) {
            $updates['quote_received_at'] = now();
        }

        if ($request->boolean('confirm')) {
            // Confirming without a quote on file is legitimate — some
            // orders CDW simply places — so this stamps the arrival too
            // rather than refusing.
            $updates['quote_received_at'] = $order->quote_received_at ?? now();
            $updates['confirmed_at'] = $order->confirmed_at ?? now();
        }

        if ($updates === []) {
            return redirect()->route('procurement.approvals', ['status' => 'ordered']);
        }

        $order->update($updates);

        return redirect()->route('procurement.approvals', ['status' => 'ordered'])
            ->with('success', trans($request->boolean('confirm')
                ? 'admin/store/general.quote_confirmed'
                : 'admin/store/general.quote_recorded'));
    }

    /**
     * Set which account an order is charged to after it was approved —
     * the common case being a lease schedule that rolled over between
     * approval and the batch actually going out.
     */
    public function setFunding(Request $request, StoreOrder $order): RedirectResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'funding_account' => 'nullable|string|in:'.implode(',', StoreOrder::fundingAccounts()),
            'lease_schedule' => 'nullable|string|max:32',
        ]);

        $account = $validated['funding_account'] ?? null;

        // The account says which of the open pair this belongs on, so the
        // form does not have to be right about it. An explicit choice still
        // wins, for a correction onto an earlier schedule.
        $schedule = $validated['lease_schedule'] ?? null;

        if ($schedule === null && SupplierAccounts::needsSchedule($account)) {
            $schedule = CsiSchedule::scheduleForAccount($account);
        }

        // Both CSI-financed accounts need the schedule, and neither is
        // called 'lease' — the values are lease_admin and lease_curriculum.
        // Matching the bare string meant the guard never fired and the
        // schedule was nulled on save, so no lease order could ever reach
        // readyForVendor() and none could be sent to CDW.
        if (SupplierAccounts::needsSchedule($account) && empty($schedule)) {
            return redirect()->route('procurement.approvals', ['status' => $order->status])
                ->with('error', trans('admin/store/general.funding_lease_needs_schedule'));
        }

        $order->update([
            'funding_account' => $account,
            'lease_schedule' => SupplierAccounts::needsSchedule($account) ? $schedule : null,
        ]);

        return redirect()->route('procurement.approvals', ['status' => $order->status])
            ->with('success', trans('admin/store/general.funding_saved'));
    }

    /**
     * Save the approver list: who may approve or decline store orders.
     * Superuser-gated, like the rest of who-can-do-what configuration.
     */
    public function saveApprovers(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->isSuperUser(), 403);

        $validated = $request->validate([
            'approvers' => 'nullable|array',
            'approvers.*' => 'integer|exists:users,id',
        ]);

        $wanted = collect($validated['approvers'] ?? [])->unique()->values();

        StoreApprover::whereNotIn('user_id', $wanted)->delete();

        foreach ($wanted as $userId) {
            StoreApprover::firstOrCreate(
                ['user_id' => $userId],
                ['created_by' => auth()->id()]
            );
        }

        return redirect()->route('procurement.index')
            ->with('success', trans('admin/store/general.approvers_saved'));
    }

    /**
     * Pull a batch of approved orders into one new requisition — the bridge
     * from the store into the real procurement chain. Each order's lines
     * are copied onto the requisition (annotated with who asked), and the
     * orders flip to `ordered` with the requisition linked, which is what
     * drives the requester-facing status from here on.
     */
    public function pullIntoRequisition(Request $request): RedirectResponse
    {
        $this->authorize('create', Requisition::class);

        $validated = $request->validate([
            'orders' => 'required|array|min:1',
            'orders.*' => 'integer|exists:store_orders,id',
            'title' => 'required|string|max:191',
        ]);

        $orders = StoreOrder::with('items', 'user')
            ->whereIn('id', $validated['orders'])
            ->where('status', 'approved')
            ->get();

        if ($orders->isEmpty()) {
            return redirect()->route('procurement.approvals', ['status' => 'approved'])
                ->with('error', trans('admin/store/general.queue_nothing_approved'));
        }

        $requisition = DB::transaction(function () use ($orders, $validated) {
            $requisition = Requisition::create([
                'title' => $validated['title'],
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            $sort = 0;
            foreach ($orders as $order) {
                foreach ($order->items as $line) {
                    RequisitionItem::create([
                        'requisition_id' => $requisition->id,
                        'catalog_item_id' => $line->catalog_item_id,
                        'description' => $line->description,
                        'vendor_sku' => $line->vendor_sku,
                        'mfr_part_number' => $line->mfr_part_number,
                        'quantity' => $line->quantity,
                        'unit_cost' => (float) $line->unit_cost,
                        'notes' => trans('admin/store/general.line_requested_by', [
                            'name' => $order->user?->username ?: ('#'.$order->user_id),
                        ]),
                        'sort_order' => $sort++,
                    ]);
                }

                $order->update(['status' => 'ordered', 'requisition_id' => $requisition->id]);
            }

            return $requisition;
        });

        return redirect()->route('purchase-orders.builder', ['requisition' => $requisition->id])
            ->with('success', trans('admin/store/general.queue_pulled', ['count' => $orders->count()]));
    }

    /**
     * Storefront management: which catalog rows the store shows, their
     * ordering, and their images.
     */
    public function storeAdmin()
    {
        $this->authorize('update', Requisition::class);

        $items = CatalogItem::with('model')
            ->active()
            ->orderBy('category')
            ->orderBy('store_sort')
            ->orderBy('name')
            ->get();

        return view('procurement.store-admin', ['items' => $items]);
    }

    /**
     * One row's storefront settings: visibility, sort, image. Called per
     * row from the management table.
     */
    public function updateStoreItem(Request $request, CatalogItem $item): RedirectResponse
    {
        $this->authorize('update', Requisition::class);

        $validated = $request->validate([
            'show_in_store' => 'nullable|boolean',
            'store_sort' => 'nullable|integer',
            'model_id' => 'nullable|integer|exists:models,id',
            'image' => 'nullable|mimes:jpg,jpeg,png,gif,webp|max:4096',
            'vendor_sku' => 'nullable|string|max:191',
            'mfr_part_number' => 'nullable|string|max:191',
            'warranty_months' => 'nullable|integer|min:0|max:120',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
        ]);

        $item->show_in_store = (bool) ($validated['show_in_store'] ?? false);
        $item->store_sort = (int) ($validated['store_sort'] ?? 0);
        $item->model_id = $validated['model_id'] ?? null;

        // Tidied rather than trimmed: the whitespace that actually arrives on
        // a pasted part number is a no-break space, which trim() leaves alone.
        $item->vendor_sku = CatalogItem::tidyIdentifier((string) ($validated['vendor_sku'] ?? '')) ?: null;
        $item->mfr_part_number = CatalogItem::tidyIdentifier((string) ($validated['mfr_part_number'] ?? '')) ?: null;
        $item->warranty_months = $validated['warranty_months'] ?? null;
        $item->supplier_id = $validated['supplier_id'] ?? null;

        if ($request->hasFile('image')) {
            $stored = $request->file('image')->store('catalog', 'public');
            if ($stored) {
                $item->image = basename($stored);
            }
        }

        $item->save();

        return redirect()->route('procurement.store-admin')
            ->with('success', trans('admin/store/general.store_item_updated'));
    }
}
