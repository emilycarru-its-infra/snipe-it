<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Transformers\OrdersTransformer;
use App\Models\Order;

class OrdersController extends Controller
{
    /**
     * Display a listing of orders.
     */
    public function index(FilterRequest $request): array
    {
        $this->authorize('view', Order::class);

        $allowed_columns = [
            'id',
            'order_number',
            'status',
            'order_date',
            'expected_date',
            'received_date',
            'order_cost',
            'tracking_number',
            'tracking_carrier',
            'created_at',
        ];

        $orders = Order::with('supplier', 'company', 'adminuser', 'items.item')
            ->withCount('items as items_count');

        // This invokes the Searchable model trait scopeTextSearch
        if ($request->filled('filter') || $request->filled('search')) {
            $orders->TextSearch($request->input('filter') ? $request->input('filter') : $request->input('search'));
        }

        if ($request->filled('order_number')) {
            $orders->where('order_number', '=', $request->input('order_number'));
        }

        if ($request->filled('status')) {
            $orders->where('status', '=', $request->input('status'));
        }

        if ($request->filled('supplier_id')) {
            $orders->where('supplier_id', '=', $request->input('supplier_id'));
        }

        if ($request->filled('company_id')) {
            $orders->where('company_id', '=', $request->input('company_id'));
        }

        // Make sure the offset and limit are actually integers and do not exceed system limits
        $offset = ($request->input('offset') > $orders->count()) ? $orders->count() : app('api_offset_value');
        $limit = app('api_limit_value');

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), $allowed_columns) ? $request->input('sort') : 'created_at';

        $orders->orderBy($sort, $order);

        $total = $orders->count();
        $orders = $orders->skip($offset)->take($limit)->get();

        return (new OrdersTransformer)->transformOrders($orders, $total);
    }

    /**
     * Display the specified order.
     *
     * @param  int  $id
     */
    public function show($id): array
    {
        $this->authorize('view', Order::class);
        $order = Order::with('supplier', 'company', 'adminuser', 'items.item')->findOrFail($id);

        return (new OrdersTransformer)->transformOrder($order);
    }
}
