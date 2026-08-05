<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order can be a staff member's early refresh of the machine already in
 * their hands: `refresh_asset_id` names that machine, and `gl_code` carries
 * the department GL code when their department is funding the purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('refresh_asset_id')->nullable()->after('location_id')->index();
            $table->string('gl_code', 64)->nullable()->after('lease_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn(['refresh_asset_id', 'gl_code']);
        });
    }
};
