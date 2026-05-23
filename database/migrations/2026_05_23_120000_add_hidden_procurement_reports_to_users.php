<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user "hide this report" preference for the procurement reports
 * landing page. Stored as a JSON array of report keys (the same `name`
 * keys the reports.blade.php list uses, e.g. `report_po_budget`).
 *
 * Null/missing = nothing hidden, the full list renders.
 */
class AddHiddenProcurementReportsToUsers extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'hidden_procurement_reports')) {
                $table->json('hidden_procurement_reports')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'hidden_procurement_reports')) {
                $table->dropColumn('hidden_procurement_reports');
            }
        });
    }
}
