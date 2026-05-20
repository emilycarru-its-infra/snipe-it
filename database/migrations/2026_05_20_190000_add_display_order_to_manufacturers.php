<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add display_order on manufacturers so the toner dashboard's
 * subsections can be re-arranged. Default 999 puts unset manufacturers
 * at the end; existing rows get sane initial values based on alphabetical
 * order so the first render is stable instead of arbitrary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manufacturers', function (Blueprint $table) {
            $table->integer('display_order')->default(999)->after('name');
        });

        // Seed initial ordering: alphabetical, stepped by 10 so manual
        // re-ordering has gaps to slot into without renumbering everything.
        $manufacturers = DB::table('manufacturers')
            ->orderBy('name')
            ->pluck('id');
        $i = 10;
        foreach ($manufacturers as $id) {
            DB::table('manufacturers')->where('id', $id)->update(['display_order' => $i]);
            $i += 10;
        }
    }

    public function down(): void
    {
        Schema::table('manufacturers', function (Blueprint $table) {
            $table->dropColumn('display_order');
        });
    }
};
