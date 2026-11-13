<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The quantity the vendor quoted on each line, kept beside the quantity we
 * order. Empty means the line was never changed after a quote, so the two
 * agree. A request quoted at three monitors and ordered at two is the case
 * this exists for: the vendor ships what the quote said unless the order
 * tells them, loudly, that it differs.
 */
return new class extends Migration
{
    private const TABLES = ['store_order_items', 'requisition_items', 'order_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'quoted_quantity')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedInteger('quoted_quantity')->nullable()->after('quantity');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'quoted_quantity')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('quoted_quantity');
                });
            }
        }
    }
};
