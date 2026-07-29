<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stretch of the store order lifecycle the requester actually sees:
 * which program the order belongs to (faculty laptop program orders get
 * special handling), when the order request went to the vendor, and the
 * shipping facts the CDW webhook lands (tracking, shipped, arrived).
 * Suppliers gain the address list vendor order requests go to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('program', 32)->nullable()->index()->after('status');
            $table->timestamp('vendor_sent_at')->nullable()->after('requisition_id');
            $table->string('tracking_number')->nullable()->after('vendor_sent_at');
            $table->timestamp('shipped_at')->nullable()->after('tracking_number');
            $table->timestamp('arrived_at')->nullable()->after('shipped_at');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('order_emails')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn(['program', 'vendor_sent_at', 'tracking_number', 'shipped_at', 'arrived_at']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('order_emails');
        });
    }
};
