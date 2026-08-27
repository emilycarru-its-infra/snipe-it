<?php

namespace App\Http\Controllers;

use App\Models\CsiSchedule;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderVendorDispatch;
use App\Services\SupplierAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin UI for the PurchaseOrder entity — the budget unit that vendor
 * orders are placed against. Shares the 'orders' permission set, since
 * managing purchase orders and orders is one responsibility.
 */
class PurchaseOrdersController extends Controller
{
    public function index(): View
    {
        $this->authorize('view', Order::class);

        return view('purchase-orders/index');
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);

        return view('purchase-orders/edit')->with('item', new PurchaseOrder);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $purchaseOrder = new PurchaseOrder;
        $this->fillFromRequest($purchaseOrder, $request);
        $purchaseOrder->created_by = auth()->id();

        if ($purchaseOrder->save()) {
            return redirect()->route('purchase-orders.index')->with('success', trans('admin/purchase-orders/message.create.success'));
        }

        return redirect()->back()->withInput()->withErrors($purchaseOrder->getErrors());
    }

    public function edit(PurchaseOrder $purchase_order): View
    {
        $this->authorize('update', Order::class);

        return view('purchase-orders/edit')->with('item', $purchase_order);
    }

    public function update(Request $request, PurchaseOrder $purchase_order): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $this->fillFromRequest($purchase_order, $request);

        if ($purchase_order->save()) {
            return redirect()->route('purchase-orders.index')->with('success', trans('admin/purchase-orders/message.update.success'));
        }

        return redirect()->back()->withInput()->withErrors($purchase_order->getErrors());
    }

    public function show(PurchaseOrder $purchase_order): View
    {
        $this->authorize('view', Order::class);

        $purchase_order->load('supplier', 'company', 'adminuser', 'orders.supplier', 'orders.invoices',
            'orders.items', 'requisitions.items.catalogItem');

        return view('purchase-orders/view', [
            'purchaseOrder' => $purchase_order,
            // Read live from the CSI mirror, never from configuration: two
            // schedules open each quarter and roll over, so a hardcoded pair
            // would be stale within three months.
            'leaseSchedules' => CsiSchedule::openScheduleNames(),
        ]);
    }

    /**
     * Send the order to the vendor's reps, and record that we did.
     *
     * This is the step that used to happen in Outlook, and the only step of a
     * requisition's life the system had no record of: an order could be sent,
     * quoted and confirmed without a single fact about it landing anywhere.
     *
     * Gated on the purchase order rather than on a permission of its own,
     * because the vendor's desk places every line against a purchase order
     * number and will not act without one — the same reason the store funnel
     * refuses to send a request with no funding account.
     *
     * A test send goes to whoever pressed the button and stamps nothing, so
     * the layout and the attachments can be checked against a real order
     * without the vendor seeing a rehearsal.
     */
    public function sendVendor(Request $request, PurchaseOrder $purchase_order): RedirectResponse
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

        // An empty submission of the picker clears the list rather than being
        // read as "leave it alone" — that is what unticking everyone means —
        // so the key is passed through whenever the form carried it.
        if ($request->has('cc_users')) {
            $validated['cc_users'] = $validated['cc_users'] ?? [];
        }

        $test = $request->boolean('test');

        $result = app(PurchaseOrderVendorDispatch::class)->send($purchase_order, auth()->user(), $validated, $test);

        if (! $result['sent']) {
            return redirect()->route('purchase-orders.show', $purchase_order->id)->with('error', $result['error']);
        }

        return redirect()->route('purchase-orders.show', $purchase_order->id)
            ->with('success', $test
                ? trans('admin/store/general.vendor_send_test_sent', ['email' => $result['recipients'][0]])
                : trans('admin/store/general.vendor_send_sent', ['emails' => implode(', ', $result['recipients'])]));
    }

    /**
     * Record the vendor's answer, and our answer to it.
     *
     * Their rep set out the loop: we send, they come back with changes — a
     * discontinued part, a substitution, an EDC they have reissued — we accept
     * those, they send the final quote, we accept that, and only then do they
     * issue an order number. Four separate facts, kept separate because each is
     * a different person's decision on a different day, and one flag would have
     * an order reading as placed while a question is still open.
     *
     * Accepting the final quote can also tell the vendor so — that is the mail
     * that gets the order placed. The decisions live in
     * {@see PurchaseOrderVendorDispatch::respond()}, shared with the API.
     */
    public function vendorResponse(Request $request, PurchaseOrder $purchase_order): RedirectResponse
    {
        $this->authorize('update', PurchaseOrder::class);

        $validated = $request->validate([
            'step' => 'required|string|in:changes,confirm,order_number',
            'vendor_changes_notes' => 'nullable|string|max:65535',
            'quote_number' => 'nullable|string|max:191',
            'quote_total' => 'nullable|numeric|min:0',
            'quote_expires_at' => 'nullable|date',
            'vendor_order_number' => 'nullable|string|max:191',
            'notify_vendor' => 'nullable|boolean',
        ]);

        $result = app(PurchaseOrderVendorDispatch::class)->respond(
            $purchase_order,
            $validated['step'],
            $validated,
            $request->boolean('notify_vendor')
        );

        if (! $result['ok']) {
            return redirect()->route('purchase-orders.show', $purchase_order->id)->with('error', $result['error']);
        }

        return redirect()->route('purchase-orders.show', $purchase_order->id)->with('success', $result['message']);
    }

    public function destroy(PurchaseOrder $purchase_order): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $purchase_order->delete();

        return redirect()->route('purchase-orders.index')->with('success', trans('admin/purchase-orders/message.delete.success'));
    }

    public function bulkDelete(Request $request): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $ids = $request->input('ids');

        if (is_array($ids) && count($ids) > 0) {
            foreach (PurchaseOrder::whereIn('id', $ids)->get() as $purchaseOrder) {
                $purchaseOrder->delete();
            }
        }

        return redirect()->route('purchase-orders.index')->with('success', trans('admin/purchase-orders/message.delete.success'));
    }

    private function fillFromRequest(PurchaseOrder $purchaseOrder, Request $request): void
    {
        $purchaseOrder->po_number = $request->input('po_number');
        $purchaseOrder->title = $request->input('title') ?: null;
        $purchaseOrder->supplier_id = $request->input('supplier_id') ?: null;
        $purchaseOrder->company_id = $request->input('company_id') ?: null;
        $purchaseOrder->fiscal_year = $request->input('fiscal_year') ?: null;
        $purchaseOrder->budget = $request->input('budget') ?: null;
        $purchaseOrder->cost_center = $request->input('cost_center') ?: null;
        $purchaseOrder->status = $request->input('status', 'open');
        $purchaseOrder->order_date = $request->input('order_date') ?: null;
        $purchaseOrder->notes = $request->input('notes') ?: null;
    }
}
