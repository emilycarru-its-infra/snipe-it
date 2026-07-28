<?php

namespace App\Http\Controllers;

use App\Mail\StoreVendorOrderMail;
use App\Models\CatalogItem;
use App\Models\EmailTemplate;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StoreApprover;
use App\Models\StoreOrder;
use App\Services\StoreOrderNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
     * Landing: queue depth, catalog health, and the doors to everything
     * else. Numbers over navigation — the page should answer "does
     * anything need me?" at a glance.
     */
    public function index()
    {
        $this->authorize('view', Requisition::class);

        return view('procurement.index', [
            'pendingOrders' => StoreOrder::pending()->count(),
            'approvedUnpulled' => StoreOrder::where('status', 'approved')->count(),
            'openRequisitions' => Requisition::open()->count(),
            'storeItemCount' => CatalogItem::inStore()->count(),
            'catalogCount' => CatalogItem::active()->count(),
            'estimateCount' => CatalogItem::inStore()->where(fn ($q) => $q->whereNull('unit_cost')->orWhere('price_type', 'estimate'))->count(),
            'approvers' => StoreApprover::with('user')->get(),
        ]);
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

        $status = $request->query('status', 'pending');
        if (! in_array($status, StoreOrder::STATUSES, true) && $status !== 'all') {
            $status = 'pending';
        }

        $orders = StoreOrder::with('items.catalogItem.supplier', 'user.department', 'decidedBy', 'requisition.purchaseOrder')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('procurement.queue', [
            'orders' => $orders,
            'selectedStatus' => $status,
            'statuses' => StoreOrder::STATUSES,
        ]);
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
        ]);

        if ($order->status !== 'pending') {
            return redirect()->route('procurement.queue')
                ->with('error', trans('admin/store/general.queue_already_decided'));
        }

        $order->update([
            'status' => $validated['decision'],
            'decision_notes' => $validated['decision_notes'] ?? null,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        StoreOrderNotifier::requester($order, $validated['decision']);

        return redirect()->route('procurement.queue')
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

        if ($orders->isEmpty()) {
            return redirect()->route('procurement.queue', ['status' => 'approved'])
                ->with('error', trans('admin/store/general.vendor_send_not_approved'));
        }

        if ($test) {
            $to = [auth()->user()->email];
            $cc = [];
        } else {
            $to = EmailTemplate::recipientsFor('store.vendor_order', $orders->first()->supplier()?->order_emails);
            $cc = EmailTemplate::ccFor('store.vendor_order', 'devicesadmins@ecuad.ca,assetsadmins@ecuad.ca');
        }

        $to = array_filter($to);

        if ($to === []) {
            return redirect()->route('procurement.queue', ['status' => 'approved'])
                ->with('error', trans('admin/store/general.vendor_send_no_recipients'));
        }

        try {
            $mail = Mail::to($to);
            if ($cc !== []) {
                $mail->cc($cc);
            }
            $mail->send(new StoreVendorOrderMail($orders, $test));
        } catch (\Throwable $e) {
            Log::warning('Vendor order email failed for store orders ['.$orders->pluck('id')->implode(',').']: '.$e->getMessage());

            return redirect()->route('procurement.queue', ['status' => 'approved'])
                ->with('error', trans('admin/store/general.vendor_send_failed', ['error' => $e->getMessage()]));
        }

        if ($test) {
            return redirect()->route('procurement.queue', ['status' => 'approved'])
                ->with('success', trans('admin/store/general.vendor_send_test_sent', ['email' => $to[0]]));
        }

        foreach ($orders as $order) {
            $order->update(['status' => 'ordered', 'vendor_sent_at' => now()]);
            StoreOrderNotifier::requester($order, 'ordered');
        }

        return redirect()->route('procurement.queue', ['status' => 'approved'])
            ->with('success', trans('admin/store/general.vendor_send_sent', ['emails' => implode(', ', $to)]));
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
            return redirect()->route('procurement.queue', ['status' => 'approved'])
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

        return redirect()->route('reports.procurement.po-builder', ['requisition' => $requisition->id])
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
            'image' => 'nullable|image|max:4096',
        ]);

        $item->show_in_store = (bool) ($validated['show_in_store'] ?? false);
        $item->store_sort = (int) ($validated['store_sort'] ?? 0);
        $item->model_id = $validated['model_id'] ?? null;

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
