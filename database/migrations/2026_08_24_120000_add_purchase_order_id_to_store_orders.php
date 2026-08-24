<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which purchase order a store order draws on.
 *
 * A store order used to reach a budget only by being pulled into a fresh
 * requisition, which minted a second purchase order for devices an existing
 * one had already been raised to buy. Naming the purchase order directly
 * lets a request stand against money that is already approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'purchase_order_id')) {
                $table->unsignedBigInteger('purchase_order_id')->nullable()->after('requisition_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'purchase_order_id')) {
                $table->dropColumn('purchase_order_id');
            }
        });
    }
};
