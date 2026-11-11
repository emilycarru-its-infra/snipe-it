<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the number an order carried before the vendor issued theirs.
 *
 * Once the vendor confirms an order, their number becomes the order's
 * `order_number`, because the shipment webhook and their invoices arrive under
 * it. The interim number is what every email, bookmark and printout made
 * before then carries, so it is kept here and still opens the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('internal_order_number')->nullable()->index()->after('vendor_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('internal_order_number');
        });
    }
};
