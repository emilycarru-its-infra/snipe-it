<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared-usage orders: techs and area managers build a cart for a lab,
 * classroom or team space rather than their own machine. `order_usage`
 * mirrors the asset-side Usage taxonomy (Assigned / Shared); `usage_note`
 * carries what space or team the order is for. Named order_usage because
 * `usage` is a MySQL reserved word — same dodge as assets.lease_usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('order_usage', 16)->default('assigned')->index()->after('program');
            $table->string('usage_note')->nullable()->after('order_usage');
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn(['order_usage', 'usage_note']);
        });
    }
};
