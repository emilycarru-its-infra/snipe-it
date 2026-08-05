<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comparable-replacement mapping: when a device of this model refreshes,
 * this is the store catalog item that replaces it. Refresh projections read
 * the catalog item's live vendor price instead of the old device's
 * purchase cost, so estimates track what things cost now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('models', function (Blueprint $table) {
            $table->unsignedBigInteger('refresh_catalog_item_id')->nullable()->after('depreciation_id');
            $table->foreign('refresh_catalog_item_id')->references('id')->on('catalog_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('models', function (Blueprint $table) {
            $table->dropForeign(['refresh_catalog_item_id']);
            $table->dropColumn('refresh_catalog_item_id');
        });
    }
};
