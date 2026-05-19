<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPurchaseOrderToOrderInvoices extends Migration
{
    public function up()
    {
        Schema::table('order_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('order_invoices', 'purchase_order_id')) {
                $table->unsignedBigInteger('purchase_order_id')->nullable()->index()->after('order_id');
            }
        });
    }

    public function down()
    {
        Schema::table('order_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('order_invoices', 'purchase_order_id')) {
                $table->dropColumn('purchase_order_id');
            }
        });
    }
}
