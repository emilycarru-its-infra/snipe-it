<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PurchaseOrder;
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

        $purchase_order->load('supplier', 'company', 'adminuser', 'orders.supplier', 'orders.invoices', 'orders.items');

        return view('purchase-orders/view', ['purchaseOrder' => $purchase_order]);
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
