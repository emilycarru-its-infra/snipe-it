<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterRequest;
use App\Http\Transformers\OrdersTransformer;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\License;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Services\StoreOrderNotifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrdersController extends Controller
{
    /**
     * The line-item types the ingest endpoint accepts, mapped to their model.
     *
     * A vendor invoice is not all hardware. CDW bills cables, SSDs, security
     * locks, rack rails and endpoint-security seats on the same account as
     * laptops, and those have no serial and never become assets. Restricting
     * line items to assets meant every such invoice was dropped without a
     * record, so its whole subtotal read as an unexplained variance.
     *
     * @var array<string, class-string>
     */
    private const LINE_ITEM_TYPES = [
        'asset' => Asset::class,
        'accessory' => Accessory::class,
        'component' => Component::class,
        'consumable' => Consumable::class,
        'license' => License::class,
    ];

    /**
     * The table each line-item type is validated to exist in.
     *
     * @var array<string, string>
     */
    private const LINE_ITEM_TABLES = [
        'asset' => 'assets',
        'accessory' => 'accessories',
        'component' => 'components',
        'consumable' => 'consumables',
        'license' => 'licenses',
    ];

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
            'invoice.invoice_type' => 'nullable|in:standard,credit,termination,buyout',
            'invoice.contract_reference' => 'nullable|string|max:191',
            // Financial adjustments (buyouts, terminations, credits) commit
            // or return money without shipping a device, so they carry no
            // asset line items.
            'items' => 'required_unless:invoice.invoice_type,buyout,credit,termination|array|min:1',
            // A line may name an asset the old way, name any other stocked
            // item type, or name nothing at all and carry only a description
            // — the last is how a one-off consumable or a service charge gets
            // onto an invoice so it reconciles. Anonymous lines are refused:
            // a line with neither a linked record nor a description is a
            // number no one can account for later.
            'items.*.asset_id' => 'nullable|integer|exists:assets,id',
            'items.*.item_type' => 'nullable|string|in:'.implode(',', array_keys(self::LINE_ITEM_TYPES)),
            'items.*.item_id' => 'nullable|integer',
            'items.*.description' => 'required_without_all:items.*.asset_id,items.*.item_id|nullable|string|max:65535',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric',
            'items.*.warranty_cost' => 'nullable|numeric',
            'items.*.tracking_number' => 'nullable|string|max:191',
            'items.*.tracking_carrier' => 'nullable|string|max:191',
            'items.*.shipped_date' => 'nullable|date',
        ]);

        // `exists` cannot be declared per line, because which table a line
        // points at is decided by its own item_type. Resolve each line to a
        // concrete [type, id] pair up front so a typo fails the request
        // rather than silently writing a dangling morph.
        $resolved = $this->resolveLineItemTargets($data['items'] ?? []);

        // Adjustment invoices move PO committed/remaining directly, which is
        // budget management, not order entry — gate them on the same
        // permission as budget allocations rather than orders.create alone.
        if (in_array($data['invoice']['invoice_type'] ?? null, ['buyout', 'credit', 'termination'], true)) {
            $this->authorize('budget_allocations.manage');
        }

        // Link to a purchase order only when one already exists under that
        // number — finance owns PO creation and budgets, so the webhook
        // never creates a budgetless PO that would skew the spend reports.
        $purchaseOrderId = empty($data['purchase_order_number'])
            ? null
            : PurchaseOrder::where('po_number', $data['purchase_order_number'])->value('id');

        $order = DB::transaction(function () use ($data, $purchaseOrderId, $resolved) {
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
                $order->fiscal_year = Helper::currentFiscalYear(Carbon::parse($order->order_date));
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
                        'invoice_type' => $data['invoice']['invoice_type'] ?? 'standard',
                        'contract_reference' => $data['invoice']['contract_reference'] ?? null,
                    ]
                );
            }

            foreach ($data['items'] ?? [] as $index => $line) {
                [$itemType, $itemId] = $resolved[$index];

                // A tracking number identifies a shipment; lines that share
                // one collapse onto the same OrderShipment.
                $shipmentId = empty($line['tracking_number']) ? null : OrderShipment::updateOrCreate(
                    ['order_id' => $order->id, 'tracking_number' => $line['tracking_number']],
                    [
                        'tracking_carrier' => $line['tracking_carrier'] ?? null,
                        'shipped_date' => $line['shipped_date'] ?? null,
                    ]
                )->id;

                // The invoice belongs in the key, not just the payload. An
                // order is commonly billed across several invoices, and CDW
                // routinely bills equipment and its AppleCare separately
                // against the same serials — order PVXX158 carries AJ7XC8E
                // for 4 Mac minis and AJ7324Y for their AppleCare. Keyed on
                // order + asset alone, ingesting the second invoice moved the
                // existing lines onto it instead of adding new ones, so the
                // first invoice lost every line item and began reporting a
                // variance equal to its whole subtotal.
                // An unlinked line has no record to key on, so its description
                // carries the identity instead — otherwise every cable and
                // fee on one invoice would collapse into a single row and a
                // re-pushed webhook would overwrite the lot.
                $key = [
                    'order_id' => $order->id,
                    'invoice_id' => $invoice?->id,
                    'item_type' => $itemType,
                    'item_id' => $itemId,
                ];

                if ($itemId === null) {
                    $key['description'] = $line['description'];
                }

                OrderItem::updateOrCreate(
                    $key,
                    [
                        'purchase_order_id' => $purchaseOrderId,
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

        $this->notifyStoreRequesters($order, $data);

        return Helper::formatStandardApiResponse(
            'success',
            (new OrdersTransformer)->transformOrder($order),
            'Order '.$order->order_number.' ingested.'
        );
    }

    /**
     * Resolve each submitted line to the [morph class, id] pair it will be
     * written with, refusing anything that does not exist.
     *
     * `asset_id` stays the shorthand it has always been — the CDW listener
     * and the store shipment flow both send it — and is equivalent to an
     * explicit `item_type: asset`. A line naming neither is stored unlinked,
     * carrying only its description and cost, which is how a consumable or a
     * one-off service charge gets onto an invoice at all.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{0: ?string, 1: ?int}>
     */
    private function resolveLineItemTargets(array $items): array
    {
        $resolved = [];

        foreach ($items as $index => $line) {
            $type = $line['item_type'] ?? (isset($line['asset_id']) ? 'asset' : null);
            $id = $line['item_id'] ?? $line['asset_id'] ?? null;

            if ($type === null || $id === null) {
                $resolved[$index] = [null, null];

                continue;
            }

            $table = self::LINE_ITEM_TABLES[$type];

            if (! DB::table($table)->where('id', $id)->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => trans('admin/orders/message.item.ingest_not_found', [
                        'type' => $type,
                        'id' => $id,
                    ]),
                ]);
            }

            $resolved[$index] = [self::LINE_ITEM_TYPES[$type], (int) $id];
        }

        return $resolved;
    }

    /**
     * Tell whoever asked for this that it has shipped.
     *
     * A store request that was folded into a bulk requisition used to go quiet
     * here: the shipment lands, assets are created, and the person waiting for a
     * laptop hears nothing until it appears on a desk. The chain back to them
     * runs through the purchase order — the vendor quotes it on the shipment,
     * the requisition carries it, and the store orders hang off the requisition.
     *
     * Fire and forget, like every other store notification: a mail hiccup must
     * not fail a webhook, because the shipment record is the truth and the email
     * is a courtesy on top of it.
     *
     * @param  array<string, mixed>  $data
     */
    private function notifyStoreRequesters(Order $order, array $data): void
    {
        if (! $order->purchase_order_id) {
            return;
        }

        $serials = collect($data['items'] ?? [])
            ->pluck('asset_id')
            ->filter()
            ->pipe(fn ($ids) => $ids->isEmpty() ? collect() : Asset::whereIn('id', $ids)->pluck('serial'))
            ->filter()
            ->values()
            ->all();

        $tracking = collect($data['items'] ?? [])->pluck('tracking_number')->filter()->first();

        $storeOrders = StoreOrder::query()
            ->whereHas('requisition', fn ($q) => $q->where('purchase_order_id', $order->purchase_order_id))
            ->with('user')
            ->get();

        foreach ($storeOrders as $storeOrder) {
            // Stamp the order before telling anybody, so a re-pushed webhook
            // does not read as a second shipment.
            $alreadyShipped = $storeOrder->shipped_at !== null;

            if (! $alreadyShipped) {
                $storeOrder->update(['shipped_at' => now(), 'tracking_number' => $tracking ?: $storeOrder->tracking_number]);
            }

            if (! $alreadyShipped) {
                StoreOrderNotifier::requester($storeOrder, 'shipped', array_filter([
                    'tracking' => $tracking,
                    'serials' => $serials,
                ]));
            }
        }
    }
}
