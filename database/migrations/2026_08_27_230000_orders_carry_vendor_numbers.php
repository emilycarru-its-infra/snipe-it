<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An order is named by the vendor's number, never by ours.
 *
 * `order_number` is what the shipment webhook and the vendor's invoices arrive
 * under, so it has to be theirs. Ours — the purchase order — is the budget the
 * order draws on and lives on `purchase_order_id`. Until they issue a number
 * the order carries `<PO>-<n>`, which reads as what it is: the n-th order
 * placed against that purchase order.
 *
 * Three shapes of row predate this and are put right here:
 *
 *   - an order whose vendor number is recorded but whose name is still the
 *     purchase order's takes the vendor's number
 *   - an order named after a purchase order that exists is linked to it and
 *     renamed `<PO>-<n>`
 *   - an order named after a purchase order that does not exist gets that
 *     purchase order raised as a budget record, is linked, and renamed
 *
 * A vendor number already in use by another order is left alone rather than
 * duplicated; that row is a hand-merge.
 */
class OrdersCarryVendorNumbers extends Migration
{
    public function up()
    {
        // Their number, where we hold it.
        foreach (DB::table('orders')->whereNotNull('vendor_order_number')->whereColumn('order_number', '!=', 'vendor_order_number')->get() as $order) {
            $taken = DB::table('orders')->where('order_number', $order->vendor_order_number)->where('id', '!=', $order->id)->exists();

            if (! $taken) {
                DB::table('orders')->where('id', $order->id)->update(['order_number' => $order->vendor_order_number]);
            }
        }

        // Ours, where an order was named after a purchase order.
        $orders = DB::table('orders')
            ->whereNull('vendor_order_number')
            ->where('order_number', 'regexp', '^P[0-9]{7,}$')
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $purchaseOrder = DB::table('purchase_orders')->where('po_number', $order->order_number)->whereNull('deleted_at')->orderBy('id')->first();

            if (! $purchaseOrder) {
                $purchaseOrderId = DB::table('purchase_orders')->insertGetId([
                    'po_number' => $order->order_number,
                    'title' => $order->notes ?: null,
                    'status' => 'open',
                    'supplier_id' => $order->supplier_id,
                    'company_id' => $order->company_id,
                    'fiscal_year' => $order->fiscal_year,
                    'order_date' => $order->order_date,
                    'created_by' => $order->created_by,
                    'created_at' => $order->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            } else {
                $purchaseOrderId = $purchaseOrder->id;
            }

            $sequence = DB::table('orders')->where('purchase_order_id', $purchaseOrderId)->where('id', '!=', $order->id)->count() + 1;
            $name = $order->order_number.'-'.$sequence;

            while (DB::table('orders')->where('order_number', $name)->exists()) {
                $name = $order->order_number.'-'.(++$sequence);
            }

            DB::table('orders')->where('id', $order->id)->update([
                'order_number' => $name,
                'purchase_order_id' => $order->purchase_order_id ?: $purchaseOrderId,
            ]);

            DB::table('order_items')->where('order_id', $order->id)->whereNull('purchase_order_id')->update(['purchase_order_id' => $purchaseOrderId]);
        }
    }

    public function down()
    {
        // Names are not restored: the vendor's number is the right one, and
        // the `<PO>-<n>` form is what a new order gets anyway.
    }
}
