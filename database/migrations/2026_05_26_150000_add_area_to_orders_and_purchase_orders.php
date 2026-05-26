<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-form `area` column on orders + purchase_orders. Mirrors the
 * Area column on the legacy `Devices Capital <FY>.xlsx` plan sheet
 * (Admin / Curriculum / Faculty Program / Research / …). Free-form
 * so operators can add new areas without code changes.
 *
 * Aggregated on the procurement dashboard for per-area budget slices
 * (PR 4 of this arc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('area', 191)->nullable()->index();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('area', 191)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('area');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('area');
        });
    }
};
