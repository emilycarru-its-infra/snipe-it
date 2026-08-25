<?php

namespace App\Http\Controllers;

use App\Mail\RequisitionVendorOrderMail;
use App\Models\CsiSchedule;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Services\SupplierAccounts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        $purchase_order->load('supplier', 'requisitions.items.catalogItem');

        // The quote and the account are recorded whether or not the send
        // succeeds: they are facts about the order — one from the vendor, one
        // our own decision — not side effects of emailing anybody.
        foreach (['quote_number', 'quote_total', 'quote_expires_at', 'funding_account', 'lease_schedule', 'order_cc'] as $field) {
            if ($request->filled($field)) {
                $purchase_order->{$field} = $validated[$field];
            }
        }

        // Picked people are stored as ids, so a name change or a new address
        // follows them. An empty submission clears the list rather than being
        // read as "leave it alone" — that is what unticking everyone means.
        if ($request->has('cc_users')) {
            $purchase_order->order_cc_users = implode(',', $validated['cc_users'] ?? []);
        }

        if ($purchase_order->isDirty()) {
            $purchase_order->save();
        }

        if ($purchase_order->status === 'cancelled' || $purchase_order->vendorOrderLines()->isEmpty()) {
            return redirect()->route('purchase-orders.show', $purchase_order->id)
                ->with('error', trans('admin/purchase-orders/general.vendor_send_needs_po'));
        }

        if (! $purchase_order->fundingResolved()) {
            return redirect()->route('purchase-orders.show', $purchase_order->id)
                ->with('error', trans(SupplierAccounts::needsSchedule($purchase_order->funding_account)
                    ? 'admin/store/general.funding_lease_needs_schedule'
                    : 'admin/purchase-orders/general.vendor_send_needs_account'));
        }

        // Both part numbers on every line. The MFR# identifies the product
        // and the EDC is what CDW places, so a line short of either is not an
        // order they can fill. Named rather than counted: the fix is a catalog
        // row somebody has to go and complete.
        if (($missing = $purchase_order->linesMissingPartNumbers())->isNotEmpty()) {
            return redirect()->route('purchase-orders.show', $purchase_order->id)
                ->with('error', trans('admin/purchase-orders/general.vendor_send_needs_part_numbers', [
                    'lines' => $missing->pluck('description')->implode(', '),
                ]));
        }

        $test = $request->boolean('test');

        if ($test) {
            $to = [auth()->user()->email];
            $cc = [];
        } else {
            $to = EmailTemplate::recipientsFor('procurement.vendor_order', $purchase_order->supplier?->order_emails);

            // The admin lists, plus whoever asked for this through the store and
            // anything typed into the form. A store request has a person waiting
            // at the end of it: once their lines are folded into a bulk
            // requisition, this is the thread that tells them it was ordered.
            $cc = array_values(array_unique(array_merge(
                EmailTemplate::ccFor('procurement.vendor_order', 'devicesadmins@ecuad.ca,assetsadmins@ecuad.ca'),
                $purchase_order->orderCcAddresses()
            )));
        }

        $to = array_filter($to);

        if ($to === []) {
            return redirect()->route('purchase-orders.show', $purchase_order->id)
                ->with('error', trans('admin/store/general.vendor_send_no_recipients'));
        }

        try {
            $mail = Mail::to($to);
            if ($cc !== []) {
                $mail->cc($cc);
            }
            $mail->send(new RequisitionVendorOrderMail($purchase_order->fresh(['supplier']), $test));
        } catch (\Throwable $e) {
            Log::warning('Vendor order email failed for purchase order '.$purchase_order->id.': '.$e->getMessage());

            return redirect()->route('purchase-orders.show', $purchase_order->id)
                ->with('error', trans('admin/store/general.vendor_send_failed', ['error' => $e->getMessage()]));
        }

        if ($test) {
            return redirect()->route('purchase-orders.show', $purchase_order->id)
                ->with('success', trans('admin/store/general.vendor_send_test_sent', ['email' => $to[0]]));
        }

        // Only a real send is a send. Stamped after the mailer returns rather
        // than before, so a bounced transport does not leave the order looking
        // placed.
        $purchase_order->vendor_sent_at = now();
        $purchase_order->save();

        return redirect()->route('purchase-orders.show', $purchase_order->id)
            ->with('success', trans('admin/store/general.vendor_send_sent', ['emails' => implode(', ', $to)]));
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
     * Recording changes reopens the basket, which is the point: their
     * substitution is the thing that has to land on the lines.
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
        ]);

        if ($purchase_order->vendor_sent_at === null) {
            return redirect()->route('purchase-orders.show', $purchase_order->id)
                ->with('error', trans('admin/purchase-orders/general.vendor_response_not_sent'));
        }

        foreach (['quote_number', 'quote_total', 'quote_expires_at'] as $field) {
            if ($request->filled($field)) {
                $purchase_order->{$field} = $validated[$field];
            }
        }

        if ($validated['step'] === 'changes') {
            $purchase_order->vendor_changes_at = now();

            if ($request->filled('vendor_changes_notes')) {
                $purchase_order->vendor_changes_notes = $validated['vendor_changes_notes'];
            }

            // A new answer from the vendor un-confirms the order: whatever we
            // accepted before, this supersedes it.
            $purchase_order->quote_confirmed_at = null;
            $message = 'admin/purchase-orders/general.vendor_changes_recorded';
        } elseif ($validated['step'] === 'confirm') {
            $purchase_order->quote_confirmed_at = $purchase_order->quote_confirmed_at ?? now();
            $message = 'admin/purchase-orders/general.vendor_quote_confirmed';
        } else {
            $purchase_order->vendor_order_number = $validated['vendor_order_number'] ?? null;

            // An order number means they placed it, so the quote we were
            // waiting on is accepted whether or not anyone pressed accept.
            $purchase_order->quote_confirmed_at = $purchase_order->quote_confirmed_at ?? now();
            $message = 'admin/purchase-orders/general.vendor_order_number_recorded';
        }

        if (! $purchase_order->save()) {
            return redirect()->route('purchase-orders.show', $purchase_order->id)
                ->withErrors($purchase_order->getErrors());
        }

        return redirect()->route('purchase-orders.show', $purchase_order->id)->with('success', trans($message));
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
