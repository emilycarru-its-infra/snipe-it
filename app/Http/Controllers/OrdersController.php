<?php

namespace App\Http\Controllers;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\License;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\PurchaseOrder;
use App\Models\CsiSchedule;
use App\Services\ArrivalAllocator;
use App\Services\SupplierAccounts;
use App\Services\OrderEtaRequest;
use Illuminate\Support\Facades\Gate;
use App\Services\VendorOrderDispatch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use League\Csv\EscapeFormula;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles the admin UI for the Order entity. The read-only JSON API lives
 * separately in App\Http\Controllers\Api\OrdersController.
 */
class OrdersController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('view', Order::class);

        // The page is a walk through every order without clicking into
        // each: collapsed rows that expand in place. Filters narrow by
        // lifecycle, and "needs allocation" surfaces the orders holding
        // hardware that arrived without a request waiting for it.
        $status = $request->query('status', 'all');
        if (! in_array($status, Order::STATUSES, true) && $status !== 'all') {
            $status = 'all';
        }

        $orders = Order::with([
            'supplier', 'company', 'shipments', 'invoices',
            'items.item' => fn ($q) => $q->withTrashed(),
        ])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate(50)
            ->withQueryString();

        $allocator = app(ArrivalAllocator::class);
        $arrivals = $allocator->unallocatedArrivals();

        if ($request->boolean('needs_allocation')) {
            $arrivalIds = $arrivals->pluck('id')->flip();
            $orders->setCollection($orders->getCollection()->filter(
                fn (Order $order) => $order->items->contains(
                    fn ($line) => $line->item_type === Asset::class
                        && $arrivalIds->has($line->item_id)
                )
            ));
        }

        return view('orders/index', [
            'orders' => $orders,
            'selectedStatus' => $status,
            'needsAllocation' => $request->boolean('needs_allocation'),
            'unmatchedCount' => $arrivals->count(),
            // What the "Ask for ETAs" sheet offers: orders with the vendor and
            // something still to arrive, oldest first — the likeliest to chase.
            'etaCandidates' => Gate::allows('update', Order::class)
                ? Order::with('supplier')
                    ->whereIn('status', ['ordered', 'shipped', 'partially_received'])
                    ->whereHas('items', fn ($q) => $q->whereNull('received_at'))
                    ->orderByRaw('COALESCE(vendor_sent_at, order_date, created_at)')
                    ->get()
                : collect(),
        ]);
    }

    /**
     * The allocation workbench on its own page: hardware that arrived
     * without a matching request, paired to the waiting requests for the
     * same model. The orders list carries only a doorway with a count.
     */
    public function unmatched(): View
    {
        $this->authorize('view', Order::class);

        $allocator = app(ArrivalAllocator::class);

        return view('orders/unmatched', [
            'arrivals' => $allocator->unallocatedArrivals(),
            'waiting' => $allocator->waitingRequests(),
        ]);
    }

    /**
     * Pair an unallocated arrival with a waiting store request — the
     * manual form of the webhook's automatic claim, for the units that
     * arrived without one (extras on a batch, or a reference CDW did not
     * carry). Model equality is enforced by the allocator.
     */
    public function allocate(Request $request): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $validated = $request->validate([
            'arrival_id' => 'required|integer|exists:assets,id',
            'waiting_id' => 'required|integer|exists:assets,id',
        ]);

        try {
            $result = app(ArrivalAllocator::class)->allocate(
                Asset::findOrFail($validated['arrival_id']),
                Asset::findOrFail($validated['waiting_id']),
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back(fallback: route('orders.unmatched'))->with('error', $e->getMessage());
        }

        return redirect()->back(fallback: route('orders.unmatched'))->with('success',
            trans('admin/orders/general.allocated', ['tag' => $result['asset']->asset_tag]));
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);

        return view('orders/edit')
            ->with('item', new Order)
            ->with('purchase_orders', PurchaseOrder::orderBy('po_number')->get());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $order = new Order;
        $this->fillFromRequest($order, $request);
        $order->status = 'ordered';
        $order->created_by = auth()->id();

        if ($order->save()) {
            return redirect()->route('orders.index')->with('success', trans('admin/orders/message.create.success'));
        }

        return redirect()->back()->withInput()->withErrors($order->getErrors());
    }

    public function edit(Order $order): View
    {
        $this->authorize('update', Order::class);

        return view('orders/edit')
            ->with('item', $order)
            ->with('purchase_orders', PurchaseOrder::orderBy('po_number')->get());
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $wasPlanned = (bool) $order->is_planned;

        $this->fillFromRequest($order, $request);

        // The pipeline's hard boundary out of Budgeting: a planned order
        // only becomes an actual order once a purchase order is attached.
        if ($wasPlanned && ! $order->is_planned && ! $order->purchase_order_id) {
            return redirect()->back()->withInput()->withErrors([
                'purchase_order_id' => trans('admin/purchase-orders/general.order_convert_requires_po'),
            ]);
        }

        if ($order->save()) {
            return redirect()->route('orders.index')->with('success', trans('admin/orders/message.update.success'));
        }

        return redirect()->back()->withInput()->withErrors($order->getErrors());
    }

    public function show(Order $order): View
    {
        $this->authorize('view', Order::class);

        $order->load('supplier', 'company', 'adminuser', 'purchaseOrder', 'items.item', 'items.catalogItem', 'items.shipment', 'items.invoice', 'shipments', 'invoices');

        return view('orders/view', [
            'order' => $order,
            // The quarter's open schedule pair, for the send panel's account
            // picker — read live from the CSI mirror, never typed.
            'leaseSchedules' => CsiSchedule::openScheduleNames(),
        ]);
    }

    /**
     * Send the order to the vendor's reps, and record that we did.
     *
     * The decisions — the readiness gates, who the mail reaches, the rule
     * that only a real send is a send — live in {@see VendorOrderDispatch},
     * shared with the API.
     */
    public function sendVendor(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

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

        // An empty submission of the picker clears the list rather than being
        // read as "leave it alone" — that is what unticking everyone means.
        if ($request->has('cc_users')) {
            $validated['cc_users'] = $validated['cc_users'] ?? [];
        }

        $test = $request->boolean('test');

        $result = app(VendorOrderDispatch::class)->send($order, auth()->user(), $validated, $test);

        if (! $result['sent']) {
            return redirect()->route('orders.show', $order->id)->with('error', $result['error']);
        }

        return redirect()->route('orders.show', $order->id)
            ->with('success', $test
                ? trans('admin/store/general.vendor_send_test_sent', ['email' => $result['recipients'][0]])
                : trans('admin/store/general.vendor_send_sent', ['emails' => implode(', ', $result['recipients'])]));
    }

    /**
     * Ask the vendor where this order is, from the order page's dialog. The
     * same send as the API's, so the two cannot disagree about who it reaches.
     */
    public function etaRequest(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

        // The dialog posts the To list as one comma-separated field.
        $request->merge(['to' => array_values(array_filter(array_map('trim',
            explode(',', (string) $request->input('to_list', ''))))) ?: null]);

        $validated = $request->validate(Api\OrdersController::ETA_RULES);
        $test = $request->boolean('test');

        $result = app(OrderEtaRequest::class)->send($order, auth()->user(), $validated, $test);

        if (! $result['sent']) {
            return redirect()->route('orders.show', $order->id)->with('error', $result['error']);
        }

        return redirect()->route('orders.show', $order->id)
            ->with('success', $test
                ? trans('admin/orders/general.eta_request_test_sent', ['email' => $result['to'][0] ?? ''])
                : trans('admin/orders/general.eta_request_sent', ['emails' => implode(', ', $result['to'])]));
    }

    /**
     * The same ask for every ticked order on the list: one email per order,
     * each to its own supplier. Orders that cannot go are counted, not fatal.
     */
    public function etaRequestBulk(Request $request): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:orders,id',
            'cc_users' => 'nullable|array',
            'cc_users.*' => 'integer|exists:users,id',
            'note' => 'nullable|string|max:65535',
        ]);

        $service = app(OrderEtaRequest::class);
        $sent = 0;
        $errors = [];

        foreach (Order::whereIn('id', $validated['ids'])->get() as $order) {
            $result = $service->send($order, auth()->user(), $validated);
            if ($result['sent']) {
                $sent++;
            } else {
                $errors[] = $order->order_number.': '.$result['error'];
            }
        }

        $message = trans('admin/orders/general.eta_request_bulk_result', ['sent' => $sent, 'failed' => count($errors)]);

        return redirect()->route('orders.index')
            ->with($errors === [] ? 'success' : 'warning', $message.($errors === [] ? '' : ' '.implode(' · ', $errors)));
    }

    /**
     * Record the vendor's answer, and our answer to it — their loop: changes,
     * the final quote we accept (telling them so, by default), and the order
     * number they issue once placed.
     */
    public function vendorResponse(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

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

        $result = app(VendorOrderDispatch::class)->respond(
            $order,
            $validated['step'],
            $validated,
            $request->boolean('notify_vendor')
        );

        if (! $result['ok']) {
            return redirect()->route('orders.show', $order->id)->with('error', $result['error']);
        }

        return redirect()->route('orders.show', $order->id)->with('success', $result['message']);
    }

    public function destroy(Order $order): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $order->delete();

        return redirect()->route('orders.index')->with('success', trans('admin/orders/message.delete.success'));
    }

    /**
     * Item types that can be attached to an order as line items, keyed by the
     * short form used in the add-item form.
     */
    public const ITEM_TYPES = [
        'asset' => Asset::class,
        'license' => License::class,
        'accessory' => Accessory::class,
        'consumable' => Consumable::class,
        'component' => Component::class,
    ];

    public function storeItem(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $typeKey = $request->input('item_type');

        if (! array_key_exists($typeKey, self::ITEM_TYPES)) {
            return redirect()->route('orders.show', $order->id)->with('error', trans('admin/orders/message.item.type_invalid'));
        }

        $itemClass = self::ITEM_TYPES[$typeKey];

        if (is_null($item = $itemClass::find($request->input('item_id_'.$typeKey)))) {
            return redirect()->route('orders.show', $order->id)->with('error', trans('admin/orders/message.item.not_found'));
        }

        $quantity = (int) $request->input('quantity', 1);

        // A line item may be attached to one of the order's own shipments
        // and billed on one of its invoices.
        $shipmentId = $request->input('shipment_id') ?: null;
        if ($shipmentId && ! $order->shipments()->whereKey($shipmentId)->exists()) {
            $shipmentId = null;
        }

        $invoiceId = $request->input('invoice_id') ?: null;
        if ($invoiceId && ! $order->invoices()->whereKey($invoiceId)->exists()) {
            $invoiceId = null;
        }

        $orderItem = new OrderItem;
        $orderItem->order_id = $order->id;
        $orderItem->shipment_id = $shipmentId;
        $orderItem->invoice_id = $invoiceId;
        $orderItem->item_type = $itemClass;
        $orderItem->item_id = $item->id;
        $orderItem->description = $request->input('description') ?: null;
        $orderItem->quantity = $quantity > 0 ? $quantity : 1;
        $orderItem->unit_cost = $request->input('unit_cost') ?: null;
        $orderItem->warranty_cost = $request->input('warranty_cost') ?: null;
        $orderItem->save();

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.item.add_success'));
    }

    public function destroyItem(Order $order, OrderItem $item): RedirectResponse
    {
        $this->authorize('update', Order::class);

        // Guard against an item id from a different order being passed in.
        if ((int) $item->order_id === (int) $order->id) {
            $item->delete();
        }

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.item.delete_success'));
    }

    /**
     * Mark a single line item as received.
     */
    public function receiveItem(Order $order, OrderItem $item): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if ((int) $item->order_id === (int) $order->id) {
            $item->markReceived();
        }

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.item.receive_success'));
    }

    /**
     * Undo receiving on a single line item.
     */
    public function unreceiveItem(Order $order, OrderItem $item): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if ((int) $item->order_id === (int) $order->id) {
            $item->markUnreceived();
        }

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.item.unreceive_success'));
    }

    public function storeShipment(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $shipment = new OrderShipment;
        $shipment->order_id = $order->id;
        $this->fillShipmentFromRequest($shipment, $request);
        $shipment->save();

        $order->recalculateStatus();

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.shipment.add_success'));
    }

    public function updateShipment(Request $request, Order $order, OrderShipment $shipment): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if ((int) $shipment->order_id === (int) $order->id) {
            $this->fillShipmentFromRequest($shipment, $request);
            $shipment->save();
            $order->recalculateStatus();
        }

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.shipment.update_success'));
    }

    public function destroyShipment(Order $order, OrderShipment $shipment): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if ((int) $shipment->order_id === (int) $order->id) {
            // Release the line items so they aren't tied to a dead shipment.
            $shipment->items()->update(['shipment_id' => null]);
            $shipment->delete();
            $order->recalculateStatus();
        }

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.shipment.delete_success'));
    }

    /**
     * Mark a shipment, and every line item assigned to it, as received.
     */
    public function receiveShipment(Order $order, OrderShipment $shipment): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if ((int) $shipment->order_id === (int) $order->id) {
            $shipment->receive();
        }

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.shipment.receive_success'));
    }

    public function storeInvoice(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $invoice = new OrderInvoice;
        $invoice->order_id = $order->id;
        $this->fillInvoiceFromRequest($invoice, $request);
        $invoice->save();

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.invoice.add_success'));
    }

    public function updateInvoice(Request $request, Order $order, OrderInvoice $invoice): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if ((int) $invoice->order_id === (int) $order->id) {
            $this->fillInvoiceFromRequest($invoice, $request);
            $invoice->save();
        }

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.invoice.update_success'));
    }

    public function destroyInvoice(Order $order, OrderInvoice $invoice): RedirectResponse
    {
        $this->authorize('update', Order::class);

        if ((int) $invoice->order_id === (int) $order->id) {
            // Release the line items so they aren't tied to a dead invoice.
            $invoice->items()->update(['invoice_id' => null]);
            $invoice->delete();
        }

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.invoice.delete_success'));
    }

    /**
     * Move an order into the cancelled terminal state.
     */
    public function cancel(Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $order->status = 'cancelled';
        $order->save();

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.cancel_success'));
    }

    /**
     * Take an order back out of cancelled and re-derive its status from
     * its line items.
     */
    public function reopen(Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $order->status = 'ordered';
        $order->save();
        $order->recalculateStatus();

        return redirect()->route('orders.show', $order->id)->with('success', trans('admin/orders/message.reopen_success'));
    }

    /**
     * Stream the order's line items as a CSV.
     */
    public function export(Order $order): StreamedResponse
    {
        $this->authorize('view', Order::class);

        $order->load('items.item', 'items.shipment');

        $filename = 'order-'.preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $order->order_number).'-'.date('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($order) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            $formatter = new EscapeFormula('`');

            fputcsv($handle, [
                trans('admin/orders/general.item_type'),
                trans('admin/orders/general.item'),
                trans('admin/orders/general.description'),
                trans('admin/orders/general.quantity'),
                trans('admin/orders/general.unit_cost'),
                trans('admin/orders/general.line_total'),
                trans('admin/orders/general.received'),
                trans('admin/orders/general.received_date'),
                trans('general.tracking_number'),
            ]);

            foreach ($order->items as $item) {
                $itemName = '';
                if ($item->item) {
                    $itemName = $item->item instanceof Asset
                        ? $item->item->present()->fullName()
                        : (string) $item->item->getAttribute('name');
                }

                $row = [
                    $item->item_type ? class_basename($item->item_type) : '',
                    $itemName,
                    (string) $item->description,
                    (int) $item->quantity,
                    $item->unit_cost,
                    (float) $item->unit_cost * (int) $item->quantity,
                    $item->received_at ? trans('general.yes') : trans('general.no'),
                    $item->received_at ? $item->received_at->format('Y-m-d') : '',
                    (string) $item->shipment?->tracking_number,
                ];

                fputcsv($handle, $formatter->escapeRecord($row));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function bulkDelete(Request $request): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $ids = $request->input('ids');

        if (is_array($ids) && count($ids) > 0) {
            foreach (Order::whereIn('id', $ids)->get() as $order) {
                $order->delete();
            }
        }

        return redirect()->route('orders.index')->with('success', trans('admin/orders/message.delete.success'));
    }

    /**
     * The order status is derived from line-item receiving, so it isn't set
     * from the edit form — only these descriptive fields are.
     */
    private function fillFromRequest(Order $order, Request $request): void
    {
        $order->order_number = $request->input('order_number');
        $order->is_planned = $request->boolean('is_planned');
        $order->fiscal_year = $request->input('fiscal_year') ?: null;
        $order->purchase_order_id = $request->input('purchase_order_id') ?: null;
        $order->supplier_id = $request->input('supplier_id') ?: null;
        $order->company_id = $request->input('company_id') ?: null;
        $order->order_date = $request->input('order_date') ?: null;
        $order->expected_date = $request->input('expected_date') ?: null;
        $order->received_date = $request->input('received_date') ?: null;
        $order->order_cost = $request->input('order_cost') ?: null;
        $order->notes = $request->input('notes') ?: null;
    }

    private function fillShipmentFromRequest(OrderShipment $shipment, Request $request): void
    {
        $shipment->tracking_number = $request->input('tracking_number') ?: null;
        $shipment->tracking_carrier = $request->input('tracking_carrier') ?: null;
        $shipment->shipped_date = $request->input('shipped_date') ?: null;
        $shipment->received_date = $request->input('received_date') ?: null;
        $shipment->notes = $request->input('notes') ?: null;
    }

    private function fillInvoiceFromRequest(OrderInvoice $invoice, Request $request): void
    {
        $invoice->invoice_number = $request->input('invoice_number');
        $invoice->invoice_date = $request->input('invoice_date') ?: null;
        $invoice->subtotal = $request->input('subtotal') ?: null;
        $invoice->tax_gst = $request->input('tax_gst') ?: null;
        $invoice->tax_pst = $request->input('tax_pst') ?: null;
        $invoice->shipping = $request->input('shipping') ?: null;
        $invoice->total = $request->input('total') ?: null;
        $invoice->notes = $request->input('notes') ?: null;
    }
}
