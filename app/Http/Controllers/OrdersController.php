<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
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

    /**
     * Item types that can be attached to an order as line items, keyed by the
     * short form used in the add-item form.
     */
    public const ITEM_TYPES = [
        'asset' => \App\Models\Asset::class,
        'license' => \App\Models\License::class,
        'accessory' => \App\Models\Accessory::class,
        'consumable' => \App\Models\Consumable::class,
        'component' => \App\Models\Component::class,
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

        $orderItem = new OrderItem;
        $orderItem->order_id = $order->id;
        $orderItem->item_type = $itemClass;
        $orderItem->item_id = $item->id;
        $orderItem->description = $request->input('description') ?: null;
        $orderItem->quantity = $quantity > 0 ? $quantity : 1;
        $orderItem->unit_cost = $request->input('unit_cost') ?: null;
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
