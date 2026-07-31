<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Which space is this for" was a free-text note for exactly one day. A
 * shared order's destination is a Location — the same thing an asset is
 * checked out to — so it becomes a real reference and the provisioner can
 * seat the arriving devices there instead of a human re-reading a string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->unsignedInteger('location_id')->nullable()->index()->after('order_usage');
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn('usage_note');
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('usage_note')->nullable()->after('order_usage');
        });

        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
    }
};
