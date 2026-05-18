<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\OrderShipment;
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

        $order->load('supplier', 'company', 'adminuser', 'items.item', 'items.shipment', 'items.invoice', 'shipments', 'invoices');

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

        if ((int) $item->order_id === (int) $order->id && is_null($item->received_at)) {
            $item->received_at = now();
            $item->save();
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
            $item->received_at = null;
            $item->save();
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
                    $itemName = $item->item_type === Asset::class
                        ? $item->item->present()->fullName()
                        : (string) $item->item->name;
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
