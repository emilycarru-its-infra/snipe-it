<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device groups on the deployment flow: cohorts that move through the
 * stages together — a classroom's desktops, a department's staff refresh.
 * A free-string label keeps it flexible: auto-suggested from the data
 * (location), settable ad-hoc in bulk from the board, drag-able as a unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployment_items', function (Blueprint $table) {
            $table->string('group_label')->nullable()->index()->after('stage_id');
        });
    }

    public function down(): void
    {
        Schema::table('deployment_items', function (Blueprint $table) {
            $table->dropColumn('group_label');
        });
    }
};
