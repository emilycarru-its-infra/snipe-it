<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\Order;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class PurchaseOrdersTransformer
{
    public function transformPurchaseOrders(Collection $purchaseOrders, $total)
    {
        $array = [];
        foreach ($purchaseOrders as $purchaseOrder) {
            $array[] = self::transformPurchaseOrder($purchaseOrder);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformPurchaseOrder(PurchaseOrder $purchaseOrder)
    {
        $remaining = $purchaseOrder->remaining();

        $array = [
            'id' => (int) $purchaseOrder->id,
            'po_number' => e($purchaseOrder->po_number),
            'title' => ($purchaseOrder->title) ? e($purchaseOrder->title) : null,
            'status' => $purchaseOrder->status,
            'fiscal_year' => ($purchaseOrder->fiscal_year) ? e($purchaseOrder->fiscal_year) : null,
            'cost_center' => ($purchaseOrder->cost_center) ? e($purchaseOrder->cost_center) : null,
            // The vendor-facing side the purchase order now owns, so an order's
            // account and quote are readable without opening the page.
            'funding_account' => $purchaseOrder->funding_account,
            'funding_label' => $purchaseOrder->fundingLabel(),
            'funding_account_number' => \App\Services\SupplierAccounts::number($purchaseOrder->funding_account),
            'lease_schedule' => $purchaseOrder->lease_schedule,
            'quote_number' => $purchaseOrder->quote_number,
            'quote_total' => $purchaseOrder->quote_total !== null ? (float) $purchaseOrder->quote_total : null,
            'quote_expires_at' => Helper::getFormattedDateObject($purchaseOrder->quote_expires_at, 'date'),
            'quote_confirmed_at' => Helper::getFormattedDateObject($purchaseOrder->quote_confirmed_at, 'datetime'),
            'vendor_sent_at' => Helper::getFormattedDateObject($purchaseOrder->vendor_sent_at, 'datetime'),
            'vendor_changes_at' => Helper::getFormattedDateObject($purchaseOrder->vendor_changes_at, 'datetime'),
            'vendor_order_number' => $purchaseOrder->vendor_order_number,
            'vendor_stage' => $purchaseOrder->vendorStage(),
            'order_lines' => $purchaseOrder->vendorOrderLines()->count(),
            'supplier' => ($purchaseOrder->supplier) ? [
                'id' => (int) $purchaseOrder->supplier->id,
                'name' => e($purchaseOrder->supplier->name),
            ] : null,
            'company' => ($purchaseOrder->company) ? [
                'id' => (int) $purchaseOrder->company->id,
                'name' => e($purchaseOrder->company->name),
            ] : null,
            'budget' => Helper::formatCurrencyOutput($purchaseOrder->budget),
            'committed' => Helper::formatCurrencyOutput($purchaseOrder->committedTotal()),
            'remaining' => ($remaining === null) ? null : Helper::formatCurrencyOutput($remaining),
            'over_budget' => $purchaseOrder->isOverBudget(),
            'order_date' => Helper::getFormattedDateObject($purchaseOrder->order_date, 'date'),
            'orders_count' => (int) ($purchaseOrder->orders_count ?? $purchaseOrder->orders->count()),
            'created_at' => Helper::getFormattedDateObject($purchaseOrder->created_at, 'datetime'),
        ];

        $array['available_actions'] = [
            'update' => Gate::allows('update', Order::class),
            'delete' => Gate::allows('delete', Order::class),
        ];

        return $array;
    }
}
