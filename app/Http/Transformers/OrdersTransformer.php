<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class OrdersTransformer
{
    public function transformOrders(Collection $orders, $total)
    {
        $array = [];
        foreach ($orders as $order) {
            $array[] = self::transformOrder($order);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformOrder(Order $order)
    {
        $array = [
            'id' => (int) $order->id,
            'order_number' => e($order->order_number),
            'status' => $order->status,
            'is_planned' => (bool) $order->is_planned,
            'fiscal_year' => ($order->fiscal_year) ? e($order->fiscal_year) : null,
            'supplier' => ($order->supplier) ? [
                'id' => (int) $order->supplier->id,
                'name' => e($order->supplier->name),
            ] : null,
            'company' => ($order->company) ? [
                'id' => (int) $order->company->id,
                'name' => e($order->company->name),
            ] : null,
            'order_date' => Helper::getFormattedDateObject($order->order_date, 'date'),
            'expected_date' => Helper::getFormattedDateObject($order->expected_date, 'date'),
            'received_date' => Helper::getFormattedDateObject($order->received_date, 'date'),
            'order_cost' => Helper::formatCurrencyOutput($order->order_cost),
            'notes' => ($order->notes) ? Helper::parseEscapedMarkedownInline($order->notes) : null,
            'items_count' => (int) ($order->items_count ?? $order->items->count()),
            'received_items_count' => $order->items->whereNotNull('received_at')->count(),
            'items' => $this->transformOrderItems($order->items),
            'invoices' => $this->transformInvoices($order->invoices),
            'created_by' => ($order->adminuser) ? [
                'id' => (int) $order->adminuser->id,
                'name' => e($order->adminuser->present()->fullName),
            ] : null,
            'created_at' => Helper::getFormattedDateObject($order->created_at, 'datetime'),
            'updated_at' => Helper::getFormattedDateObject($order->updated_at, 'datetime'),
        ];

        $array['available_actions'] = [
            'update' => Gate::allows('update', Order::class),
            'delete' => Gate::allows('delete', Order::class),
        ];

        return $array;
    }

    public function transformOrderItems($items)
    {
        $array = [];
        foreach ($items as $item) {
            $array[] = self::transformOrderItem($item);
        }

        return $array;
    }

    public function transformOrderItem(OrderItem $item)
    {
        return [
            'id' => (int) $item->id,
            'description' => ($item->description) ? e($item->description) : null,
            'quantity' => (int) $item->quantity,
            'unit_cost' => Helper::formatCurrencyOutput($item->unit_cost),
            'warranty_cost' => Helper::formatCurrencyOutput($item->warranty_cost),
            'received' => ! is_null($item->received_at),
            'received_at' => Helper::getFormattedDateObject($item->received_at, 'datetime'),
            'invoice' => ($item->invoice_id && $item->invoice) ? e($item->invoice->invoice_number) : null,
            'item' => ($item->item_type && $item->item) ? [
                'id' => (int) $item->item->id,
                'name' => e($item->item->name),
                'type' => class_basename($item->item_type),
            ] : null,
        ];
    }

    public function transformInvoices($invoices)
    {
        $array = [];
        foreach ($invoices as $invoice) {
            $array[] = [
                'id' => (int) $invoice->id,
                'invoice_number' => e($invoice->invoice_number),
                'invoice_date' => Helper::getFormattedDateObject($invoice->invoice_date, 'date'),
                'subtotal' => Helper::formatCurrencyOutput($invoice->subtotal),
                'tax_gst' => Helper::formatCurrencyOutput($invoice->tax_gst),
                'tax_pst' => Helper::formatCurrencyOutput($invoice->tax_pst),
                'shipping' => Helper::formatCurrencyOutput($invoice->shipping),
                'total' => Helper::formatCurrencyOutput($invoice->total),
            ];
        }

        return $array;
    }
}
