<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mark already-invoiced soft-cost lines as received.
     *
     * A line carrying warranty cost and no equipment cost — AppleCare, onsite
     * support — bills against hardware invoiced elsewhere. Nothing arrives in
     * a box for it, so it could never be ticked off and its order could never
     * complete. PVXX158 read "4 of 8 received" with all four Mac minis in
     * service since June; PMZP706 sat at partially_received on 40 such lines
     * against one genuine outstanding item.
     *
     * Only lines already billed on an invoice are stamped: an invoice is the
     * vendor confirming the cover was sold. Uninvoiced lines stay open, which
     * is what a planned or forecast line should do.
     */
    public function up(): void
    {
        DB::table('order_items')
            ->whereNull('received_at')
            ->whereNotNull('invoice_id')
            ->where('unit_cost', '=', 0)
            ->where('warranty_cost', '>', 0)
            ->update(['received_at' => now()]);

        // Line-item receipt drives Order::recalculateStatus(), which the model
        // fires on save. This runs in raw SQL, so re-derive the affected
        // orders here rather than leaving them stale until the next edit.
        $orders = DB::table('orders')->whereNull('deleted_at')->pluck('id');
        foreach ($orders as $orderId) {
            $order = Order::find($orderId);
            $order?->recalculateStatus();
        }
    }

    public function down(): void
    {
        // Not reversible in a meaningful way: received_at carries no marker
        // saying which stamps came from this migration, and clearing every
        // soft-cost receipt would also undo any a human ticked by hand.
    }
};
