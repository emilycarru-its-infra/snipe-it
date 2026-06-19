<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-serial lease decisions. A lease decision can now target a single
 * asset (asset_id set) as well as a whole contract (asset_id null). The
 * Per-Serial Disposition Grid uses this so each device can carry its own
 * buyout / return / extend / replace call and note — falling back to the
 * contract-level decision when no per-serial one exists.
 */
class AddAssetIdToLeaseDecisions extends Migration
{
    public function up()
    {
        if (Schema::hasTable('lease_decisions') && ! Schema::hasColumn('lease_decisions', 'asset_id')) {
            Schema::table('lease_decisions', function (Blueprint $table) {
                $table->unsignedInteger('asset_id')->nullable()->after('contract_reference')->index();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('lease_decisions') && Schema::hasColumn('lease_decisions', 'asset_id')) {
            Schema::table('lease_decisions', function (Blueprint $table) {
                $table->dropColumn('asset_id');
            });
        }
    }
}
