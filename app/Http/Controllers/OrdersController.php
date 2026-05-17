<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handles the admin UI for the Order entity. The read-only JSON API lives
 * separately in App\Http\Controllers\Api\OrdersController.
 */
class OrdersController extends Controller
{
    public function index(): View
    {
        $this->authorize('view', Order::class);

        $orders = Order::with('supplier', 'company')
            ->withCount('items as items_count')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('orders/index', compact('orders'));
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);

        return view('orders/edit')->with('item', new Order);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $order = new Order;
        $this->fillFromRequest($order, $request);
        $order->created_by = auth()->id();

        if ($order->save()) {
            return redirect()->route('orders.index')->with('success', trans('admin/orders/message.create.success'));
        }

        return redirect()->back()->withInput()->withErrors($order->getErrors());
    }

    public function edit(Order $order): View
    {
        $this->authorize('update', Order::class);

        return view('orders/edit')->with('item', $order);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $this->fillFromRequest($order, $request);

        if ($order->save()) {
            return redirect()->route('orders.index')->with('success', trans('admin/orders/message.update.success'));
        }

        return redirect()->back()->withInput()->withErrors($order->getErrors());
    }

    public function show(Order $order): View
    {
        $this->authorize('view', Order::class);

        $order->load('supplier', 'company', 'adminuser', 'items.item');

        return view('orders/view', compact('order'));
    }

    public function destroy(Order $order): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $order->delete();

        return redirect()->route('orders.index')->with('success', trans('admin/orders/message.delete.success'));
    }

    private function fillFromRequest(Order $order, Request $request): void
    {
        $order->order_number = $request->input('order_number');
        $order->status = $request->input('status', 'ordered');
        $order->supplier_id = $request->input('supplier_id') ?: null;
        $order->company_id = $request->input('company_id') ?: null;
        $order->order_date = $request->input('order_date') ?: null;
        $order->expected_date = $request->input('expected_date') ?: null;
        $order->received_date = $request->input('received_date') ?: null;
        $order->order_cost = $request->input('order_cost') ?: null;
        $order->tracking_number = $request->input('tracking_number') ?: null;
        $order->tracking_carrier = $request->input('tracking_carrier') ?: null;
        $order->notes = $request->input('notes') ?: null;
    }
}
