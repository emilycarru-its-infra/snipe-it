<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Not every wave replaces something. A refresh swaps an end-of-life
 * machine for its successor, and the board rightly reports on the
 * outgoing device — which one, what it cost, when it was due. A new
 * teaching lab replaces nothing: those columns render a wall of dashes
 * and a projected replacement spend for a replacement that is not
 * happening.
 *
 * moves_devices already covers the waves that buy nothing at all
 * (relocations, exhibit allocations). This is the other axis: waves that
 * do buy, but replace nothing. Refresh keeps the flag; the net-new
 * shapes lose it.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('deployment_types', function (Blueprint $table) {
            $table->boolean('replaces_devices')->default(true)->after('moves_devices');
        });

        // Refresh is the one that replaces. Everything else buys new, moves
        // what we own, or is too ad-hoc to assume — and a wave whose items
        // actually name a replacement shows the columns regardless, so
        // guessing false here costs nothing.
        DB::table('deployment_types')
            ->whereIn('slug', ['new_hire', 'lab_classroom', 'ad_hoc', 'exhibit', 'relocation'])
            ->update(['replaces_devices' => false]);
    }

    public function down()
    {
        Schema::table('deployment_types', function (Blueprint $table) {
            $table->dropColumn('replaces_devices');
        });
    }
};
