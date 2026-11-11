<?php

namespace App\Http\Transformers;

use App\Routing\StableRouteKeys;
use App\Helpers\Helper;
use App\Models\Order;
use App\Models\OrderInvoice;
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
            'route_key' => StableRouteKeys::keyOf($order),
            'order_number' => e($order->order_number),
            // What tables and links show: the vendor's number once there is
            // one, with ours beside it.
            'display_number' => e($order->primaryNumber()),
            'secondary_number' => $order->secondaryNumber() !== null ? e($order->secondaryNumber()) : null,
            'internal_order_number' => $order->internal_order_number !== null ? e($order->internal_order_number) : null,
            'url' => route('orders.show', $order),
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
            'purchase_order' => ($order->purchaseOrder) ? [
                'id' => (int) $order->purchaseOrder->id,
                'po_number' => e($order->purchaseOrder->po_number),
            ] : null,
            // The vendor loop: the account it is placed against, the quote
            // that came back, when it went and what they answered.
            'funding_account' => $order->funding_account,
            'funding_label' => $order->fundingLabel(),
            'funding_account_number' => \App\Services\SupplierAccounts::number($order->funding_account),
            'lease_schedule' => $order->lease_schedule,
            'quote_number' => $order->quote_number,
            'quote_total' => $order->quote_total !== null ? (float) $order->quote_total : null,
            'quote_expires_at' => Helper::getFormattedDateObject($order->quote_expires_at, 'date'),
            'quote_confirmed_at' => Helper::getFormattedDateObject($order->quote_confirmed_at, 'datetime'),
            'vendor_sent_at' => Helper::getFormattedDateObject($order->vendor_sent_at, 'datetime'),
            'vendor_changes_at' => Helper::getFormattedDateObject($order->vendor_changes_at, 'datetime'),
            'vendor_order_number' => $order->vendor_order_number,
            'vendor_stage' => $order->vendorStage(),
            // When we last asked the vendor where it is, and how often.
            'last_eta_request_at' => Helper::getFormattedDateObject($order->eta_requested_at, 'datetime'),
            'eta_request_count' => (int) $order->eta_request_count,
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
            'catalog_item_id' => $item->catalog_item_id ? (int) $item->catalog_item_id : null,
            'vendor_sku' => $item->vendor_sku,
            'mfr_part_number' => $item->mfr_part_number,
            'quantity' => (int) $item->quantity,
            'unit_of_measure' => $item->unit_of_measure,
            'unit_cost' => Helper::formatCurrencyOutput($item->unit_cost),
            'warranty_cost' => Helper::formatCurrencyOutput($item->warranty_cost),
            'received' => ! is_null($item->received_at),
            'received_at' => Helper::getFormattedDateObject($item->received_at, 'datetime'),
            'invoice' => ($item->invoice_id && $item->invoice) ? e($item->invoice->invoice_number) : null,
            'item' => ($item->item_type && $item->item) ? [
                'id' => (int) $item->item->getKey(),
                'name' => e($item->item->getAttribute('name')),
                'type' => class_basename($item->item_type),
            ] : null,
        ];
    }

    public function transformInvoices($invoices)
    {
        $array = [];
        foreach ($invoices as $invoice) {
            $array[] = $this->transformInvoice($invoice);
        }

        return $array;
    }

    public function transformInvoice(OrderInvoice $invoice)
    {
        return [
            'id' => (int) $invoice->id,
            'invoice_number' => e($invoice->invoice_number),
            'invoice_date' => Helper::getFormattedDateObject($invoice->invoice_date, 'date'),
            'subtotal' => Helper::formatCurrencyOutput($invoice->subtotal),
            'tax_gst' => Helper::formatCurrencyOutput($invoice->tax_gst),
            'tax_pst' => Helper::formatCurrencyOutput($invoice->tax_pst),
            'shipping' => Helper::formatCurrencyOutput($invoice->shipping),
            'total' => Helper::formatCurrencyOutput($invoice->total),
            'order_id' => $invoice->order_id ? (int) $invoice->order_id : null,
            'purchase_order_id' => $invoice->purchase_order_id ? (int) $invoice->purchase_order_id : null,
            'approval_status' => $invoice->approval_status,
            'approved_at' => Helper::getFormattedDateObject($invoice->approved_at, 'datetime'),
            'approved_by' => $invoice->approved_by ? (int) $invoice->approved_by : null,
            'is_final_invoice' => (bool) $invoice->is_final_invoice,
            'usage_tag' => $invoice->usage_tag !== null ? e($invoice->usage_tag) : null,
            'notes' => $invoice->notes !== null ? e($invoice->notes) : null,
            'invoice_type' => $invoice->invoice_type,
            'payment' => $this->transformPayment($invoice),
        ];
    }

    /**
     * Where the invoice stands in accounts payable, as the finance system
     * last reported it.
     */
    public function transformPayment(OrderInvoice $invoice): array
    {
        return [
            'voucher_number' => $invoice->ap_voucher_number !== null ? e($invoice->ap_voucher_number) : null,
            'status' => $invoice->ap_status,
            'paid_at' => Helper::getFormattedDateObject($invoice->ap_paid_at, 'date'),
            'paid_amount' => $invoice->ap_paid_amount !== null ? Helper::formatCurrencyOutput($invoice->ap_paid_amount) : null,
            'payment_method' => $invoice->ap_payment_method !== null ? e($invoice->ap_payment_method) : null,
            'payment_reference' => $invoice->ap_payment_reference !== null ? e($invoice->ap_payment_reference) : null,
            'synced_at' => Helper::getFormattedDateObject($invoice->ap_synced_at, 'datetime'),
            'difference' => $invoice->paymentDifference(),
        ];
    }

    /**
     * One row of the invoices list: the invoice with what it hangs off.
     */
    public function transformInvoiceRow(OrderInvoice $invoice): array
    {
        $order = $invoice->order;
        $purchaseOrder = $invoice->purchaseOrder ?? $order?->purchaseOrder;

        return $this->transformInvoice($invoice) + [
            'route_key' => StableRouteKeys::keyOf($invoice),
            'url' => route('invoices.show', $invoice),
            'supplier' => $order?->supplier ? [
                'id' => (int) $order->supplier->id,
                'name' => e($order->supplier->name),
            ] : null,
            'order' => $order ? [
                'id' => (int) $order->id,
                'display_number' => e($order->primaryNumber()),
                'secondary_number' => $order->secondaryNumber() !== null ? e($order->secondaryNumber()) : null,
                'url' => route('orders.show', $order),
            ] : null,
            'purchase_order' => $purchaseOrder ? [
                'id' => (int) $purchaseOrder->id,
                'po_number' => e($purchaseOrder->po_number),
                'url' => route('purchase-orders.show', $purchaseOrder),
            ] : null,
            'tax_total' => Helper::formatCurrencyOutput($invoice->taxTotal()),
            'okp_status' => $invoice->okp_status,
            'okp_label' => e($invoice->okayToPayLabel()),
        ];
    }

    public function transformInvoiceRows(Collection $invoices, int $total): array
    {
        return (new DatatablesTransformer)->transformDatatables(
            $invoices->map(fn (OrderInvoice $invoice) => $this->transformInvoiceRow($invoice))->all(),
            $total
        );
    }
}
