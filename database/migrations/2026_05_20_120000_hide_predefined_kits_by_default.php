<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hide the Predefined Kits sidebar entry by default.
 *
 * The toggle was added in PR #32 with a default of ON to preserve upstream
 * behaviour. ECU's install never used kits; flipping the default keeps the
 * sidebar focused on day-to-day work. Existing settings rows are also
 * updated so the change takes effect without touching the settings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('show_predefined_kits')->default(false)->change();
        });

        DB::table('settings')->update(['show_predefined_kits' => 0]);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('show_predefined_kits')->default(true)->change();
        });

        DB::table('settings')->update(['show_predefined_kits' => 1]);
    }
};
