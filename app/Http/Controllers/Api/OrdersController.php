<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Transformers\OrdersTransformer;
use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'created_at',
        ];

        $orders = Order::with('supplier', 'company', 'adminuser', 'items.item', 'items.invoice', 'invoices')
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
        $order = Order::with('supplier', 'company', 'adminuser', 'items.item', 'items.invoice', 'invoices')->findOrFail($id);

        return (new OrdersTransformer)->transformOrder($order);
    }

    /**
     * Ingest an order pushed from an external procurement source — the CDW
     * orders webhook. Upserts the order, its invoice, its shipments and its
     * line items from a normalized payload in a single transaction.
     *
     * Idempotent: the order is keyed by order_number, the invoice by
     * invoice_number, each shipment by tracking_number and each line item by
     * its linked asset. A re-pushed webhook only fills gaps rather than
     * duplicating records.
     */
    public function ingest(Request $request): array
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'order_number' => 'required|string|max:191',
            'purchase_order_number' => 'nullable|string|max:191',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'order_date' => 'nullable|date',
            'notes' => 'nullable|string|max:65535',
            'invoice' => 'nullable|array',
            'invoice.invoice_number' => 'required_with:invoice|string|max:191',
            'invoice.invoice_date' => 'nullable|date',
            'invoice.subtotal' => 'nullable|numeric',
            'invoice.tax_gst' => 'nullable|numeric',
            'invoice.tax_pst' => 'nullable|numeric',
            'invoice.shipping' => 'nullable|numeric',
            'invoice.total' => 'nullable|numeric',
            'items' => 'required|array|min:1',
            'items.*.asset_id' => 'required|integer|exists:assets,id',
            'items.*.description' => 'nullable|string|max:65535',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric',
            'items.*.warranty_cost' => 'nullable|numeric',
            'items.*.tracking_number' => 'nullable|string|max:191',
            'items.*.tracking_carrier' => 'nullable|string|max:191',
            'items.*.shipped_date' => 'nullable|date',
        ]);

        // Link to a purchase order only when one already exists under that
        // number — finance owns PO creation and budgets, so the webhook
        // never creates a budgetless PO that would skew the spend reports.
        $purchaseOrderId = empty($data['purchase_order_number'])
            ? null
            : PurchaseOrder::where('po_number', $data['purchase_order_number'])->value('id');

        $order = DB::transaction(function () use ($data, $purchaseOrderId) {
            $order = Order::firstOrNew(['order_number' => $data['order_number']]);

            if (! $order->exists) {
                $order->status = 'ordered';
                $order->is_planned = false;
                $order->created_by = auth()->id();
            }

            $order->purchase_order_id = $purchaseOrderId ?? $order->purchase_order_id;
            $order->supplier_id = $data['supplier_id'] ?? $order->supplier_id;
            $order->order_date = $data['order_date'] ?? $order->order_date;
            $order->notes = $data['notes'] ?? $order->notes;

            // Stamp the fiscal year from the order date so order-FY
            // attribution (procurement dashboard + invoice approval queue)
            // works without a manual edit. Only fill when empty — never
            // override a value finance has already set.
            if (empty($order->fiscal_year) && ! empty($order->order_date)) {
                $order->fiscal_year = Helper::currentFiscalYear(\Carbon\Carbon::parse($order->order_date));
            }

            $order->save();

            $invoice = null;
            if (! empty($data['invoice'])) {
                $invoice = OrderInvoice::updateOrCreate(
                    ['order_id' => $order->id, 'invoice_number' => $data['invoice']['invoice_number']],
                    [
                        'purchase_order_id' => $purchaseOrderId,
                        'invoice_date' => $data['invoice']['invoice_date'] ?? null,
                        'subtotal' => $data['invoice']['subtotal'] ?? 0,
                        'tax_gst' => $data['invoice']['tax_gst'] ?? 0,
                        'tax_pst' => $data['invoice']['tax_pst'] ?? 0,
                        'shipping' => $data['invoice']['shipping'] ?? 0,
                        'total' => $data['invoice']['total'] ?? 0,
                    ]
                );
            }

            foreach ($data['items'] as $line) {
                // A tracking number identifies a shipment; lines that share
                // one collapse onto the same OrderShipment.
                $shipmentId = empty($line['tracking_number']) ? null : OrderShipment::updateOrCreate(
                    ['order_id' => $order->id, 'tracking_number' => $line['tracking_number']],
                    [
                        'tracking_carrier' => $line['tracking_carrier'] ?? null,
                        'shipped_date' => $line['shipped_date'] ?? null,
                    ]
                )->id;

                OrderItem::updateOrCreate(
                    ['order_id' => $order->id, 'item_type' => Asset::class, 'item_id' => $line['asset_id']],
                    [
                        'purchase_order_id' => $purchaseOrderId,
                        'invoice_id' => $invoice?->id,
                        'shipment_id' => $shipmentId,
                        'description' => $line['description'] ?? null,
                        'quantity' => $line['quantity'] ?? 1,
                        'unit_cost' => $line['unit_cost'] ?? 0,
                        'warranty_cost' => $line['warranty_cost'] ?? 0,
                    ]
                );
            }

            $order->recalculateStatus();

            return $order;
        });

        $order = Order::with('supplier', 'company', 'adminuser', 'items.item', 'items.invoice', 'invoices')
            ->findOrFail($order->id);

        return Helper::formatStandardApiResponse(
            'success',
            (new OrdersTransformer)->transformOrder($order),
            'Order '.$order->order_number.' ingested.'
        );
    }
}
