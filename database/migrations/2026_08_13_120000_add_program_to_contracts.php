<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The academic program a contract's software belongs to — 3D Animation,
 * Industrial Design, New Media + Sound Arts, and so on.
 *
 * Sourced from the TDX contract's Account (`AccountName`), which already
 * carries this on all 192 contracts and is the same axis the annual software
 * review sessions are split along. One program per contract, treated as the
 * primary: usage observed outside it is reported as spill against the primary
 * rather than reattributed, so no pivot is needed here.
 *
 * Indexed because grouping utilization by program is the whole reason the
 * column exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('program')->nullable()->index()->after('fiscal_year');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('program');
        });
    }
};
