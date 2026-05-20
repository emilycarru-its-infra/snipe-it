<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add display_order on asset models so the toner dashboard's flat printer
 * grid can be drag-and-drop reordered. Default 999 puts unset models at
 * the end; cards then fall back to name ASC. The dashboard JS PATCHes
 * a new value when the user re-arranges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('models', function (Blueprint $table) {
            $table->integer('display_order')->default(999)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('models', function (Blueprint $table) {
            $table->dropColumn('display_order');
        });
    }
};
