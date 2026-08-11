<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who else hears about the order.
 *
 * Two reasons this lives on the requisition rather than being typed fresh into
 * each send. A re-send after the vendor proposes a substitution has to reach the
 * same audience as the first one, and a request that started in the store has a
 * person waiting at the end of it — once their lines are folded into a bulk
 * requisition, the order email is the thread that tells them it was placed.
 * Store requesters are derived from the linked store orders; this column is for
 * the addresses that are nobody's account, like a department head.
 */
class AddOrderCcToRequisitions extends Migration
{
    public function up()
    {
        Schema::table('requisitions', function (Blueprint $table) {
            if (! Schema::hasColumn('requisitions', 'order_cc')) {
                $table->text('order_cc')->nullable()->after('vendor_order_number');
            }
        });
    }

    public function down()
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('order_cc');
        });
    }
}
