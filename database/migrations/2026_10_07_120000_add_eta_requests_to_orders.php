<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When we last asked the vendor where an order is, and how many times. Chasing
 * an ETA is routine, and the second ask reads differently from the first — the
 * count is what tells whoever picks the order up next that it has been asked
 * already, without reading the mailbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('eta_requested_at')->nullable()->after('vendor_order_number');
            $table->unsignedInteger('eta_request_count')->default(0)->after('eta_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['eta_requested_at', 'eta_request_count']);
        });
    }
};
