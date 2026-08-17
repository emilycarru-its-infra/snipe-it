<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lease decision can now carry the fiscal year a refresh was pushed
 * to. Planning's "push to next FY" records an extend decision with this
 * stamped, the planning list for the original year drops the device, and
 * the target year's list picks it up — the deferral is a queryable fact,
 * not a note.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('lease_decisions', function (Blueprint $table) {
            $table->string('deferred_to_fy', 191)->nullable()->after('decision_date');
        });
    }

    public function down()
    {
        Schema::table('lease_decisions', function (Blueprint $table) {
            $table->dropColumn('deferred_to_fy');
        });
    }
};
