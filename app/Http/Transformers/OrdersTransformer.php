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
            'tracking_number' => ($order->tracking_number) ? e($order->tracking_number) : null,
            'tracking_carrier' => ($order->tracking_carrier) ? e($order->tracking_carrier) : null,
            'tracking_url' => Helper::trackingUrl($order->tracking_carrier, $order->tracking_number),
            'notes' => ($order->notes) ? Helper::parseEscapedMarkedownInline($order->notes) : null,
            'items_count' => (int) ($order->items_count ?? $order->items->count()),
            'items' => $this->transformOrderItems($order->items),
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
            'item' => ($item->item_type && $item->item) ? [
                'id' => (int) $item->item->id,
                'name' => e($item->item->name),
                'type' => class_basename($item->item_type),
            ] : null,
        ];
    }
}
