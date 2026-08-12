<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who to copy, as people rather than as typed addresses.
 *
 * Nearly everyone copied on an order has an account here, and picking them beats
 * typing them: an address with a transposed letter bounces silently, and a
 * stored id follows somebody through a name change or a new address. The
 * free-text column stays for the exception it was really for — a vendor contact
 * with no account in this system.
 */
class AddOrderCcUsersToPurchaseOrders extends Migration
{
    public function up()
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'order_cc_users')) {
                $table->string('order_cc_users')->nullable()->after('order_cc');
            }
        });
    }

    public function down()
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('order_cc_users');
        });
    }
}
