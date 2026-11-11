<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Http\Controllers\Api\OrderInvoicesController as ApiOrderInvoicesController;
use App\Models\Actionlog;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\Supplier;
use App\Services\Leasing\OkayToPayHistory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vendor invoices as records of their own: every invoice across orders, and a
 * page for each one. Until these, an invoice was only ever a row on its order,
 * so the questions finance asks of one invoice — does it add up, was it
 * approved, has it been paid — had no single place to be answered.
 *
 * Read on the order permissions, like the order pages; the moves on the page
 * (approval, OK to pay) keep the gates they have in the approval queue.
 */
class OrderInvoicesController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('view', Order::class);

        // The toolbar search's "see all" link arrives as ?search=.
        if (! $request->filled('q') && $request->filled('search')) {
            $request->merge(['q' => $request->query('search')]);
        }

        $filters = array_map('trim', array_filter(
            $request->only(ApiOrderInvoicesController::FILTERS),
            fn ($value) => is_string($value) && trim($value) !== ''
        ));

        $fiscalYears = Order::query()->whereNotNull('fiscal_year')->where('fiscal_year', '!=', '')
            ->distinct()->pluck('fiscal_year')
            ->push(Helper::currentFiscalYear())
            ->unique()->sort()->values();

        return view('invoices.index', [
            'filters' => $filters,
            'apiUrl' => route('api.order-invoices.index', $filters),
            'suppliers' => Supplier::query()
                ->whereIn('id', Order::query()->whereHas('invoices')->whereNotNull('supplier_id')->select('supplier_id'))
                ->orderBy('name')->get(['id', 'name']),
            'fiscalYears' => $fiscalYears,
        ]);
    }

    public function show(OrderInvoice $invoice): View
    {
        $this->authorize('view', Order::class);
        abort_unless($invoice->isVisibleToCurrentUser(), 404);

        $invoice->load([
            'order.supplier', 'order.purchaseOrder', 'purchaseOrder', 'approver', 'okayToPayDelivery',
            'items.item' => fn ($query) => $query->withTrashed(),
            'items.shipment',
        ]);

        $purchaseOrder = $invoice->purchaseOrder ?? $invoice->order?->purchaseOrder;
        $linesTotal = (float) $invoice->items->sum->lineTotal();

        return view('invoices.show', [
            'invoice' => $invoice,
            'order' => $invoice->order,
            'purchaseOrder' => $purchaseOrder,
            'linesTotal' => $linesTotal,
            // Only a shipping invoice is expected to add up to its lines; an
            // adjustment carries none by design. See OrderInvoice::expectedSubtotal().
            'linesDifference' => $invoice->isAdjustment() ? null : round((float) $invoice->subtotal - $linesTotal, 2),
            'componentsDifference' => $invoice->componentsDifference(),
            'paymentDifference' => $invoice->paymentDifference(),
            'poInvoiced' => $purchaseOrder?->invoicedTotal(),
            'poRemaining' => $purchaseOrder?->remaining(),
            'okpLabel' => $invoice->okayToPayLabel(),
            'okpHistorical' => OkayToPayHistory::blocked($invoice),
            'history' => Actionlog::with('adminuser')
                ->where('item_type', OrderInvoice::class)
                ->where('item_id', $invoice->id)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(100)
                ->get(),
        ]);
    }
}
