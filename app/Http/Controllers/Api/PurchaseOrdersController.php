<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Transformers\PurchaseOrdersTransformer;
use App\Models\Order;
use App\Models\PurchaseOrder;

class PurchaseOrdersController extends Controller
{
    /**
     * Display a listing of purchase orders.
     */
    public function index(FilterRequest $request): array
    {
        $this->authorize('view', Order::class);

        $allowed_columns = [
            'id',
            'po_number',
            'title',
            'fiscal_year',
            'budget',
            'cost_center',
            'status',
            'order_date',
            'created_at',
        ];

        $purchaseOrders = PurchaseOrder::with('supplier', 'company', 'adminuser', 'orders.invoices', 'orders.items')
            ->withCount('orders as orders_count');

        if ($request->filled('filter') || $request->filled('search')) {
            $purchaseOrders->TextSearch($request->input('filter') ? $request->input('filter') : $request->input('search'));
        }

        if ($request->filled('status')) {
            $purchaseOrders->where('status', '=', $request->input('status'));
        }

        if ($request->filled('fiscal_year')) {
            $purchaseOrders->where('fiscal_year', '=', $request->input('fiscal_year'));
        }

        if ($request->filled('supplier_id')) {
            $purchaseOrders->where('supplier_id', '=', $request->input('supplier_id'));
        }

        $offset = ($request->input('offset') > $purchaseOrders->count()) ? $purchaseOrders->count() : app('api_offset_value');
        $limit = app('api_limit_value');

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), $allowed_columns) ? $request->input('sort') : 'created_at';

        $purchaseOrders->orderBy($sort, $order);

        $total = $purchaseOrders->count();
        $purchaseOrders = $purchaseOrders->skip($offset)->take($limit)->get();

        return (new PurchaseOrdersTransformer)->transformPurchaseOrders($purchaseOrders, $total);
    }

    /**
     * Display the specified purchase order.
     *
     * @param  int  $id
     */
    public function show($id): array
    {
        $this->authorize('view', Order::class);
        $purchaseOrder = PurchaseOrder::with('supplier', 'company', 'adminuser', 'orders.invoices', 'orders.items')->findOrFail($id);

        return (new PurchaseOrdersTransformer)->transformPurchaseOrder($purchaseOrder);
    }
}
