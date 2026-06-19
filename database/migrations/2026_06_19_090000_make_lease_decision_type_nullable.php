<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-device disposition notes. The Per-Serial Disposition Grid no longer
 * stores a manual buyout/return/extend decision — the disposition is derived
 * from the asset's own Snipe status + Decommissioned Date. What stays editable
 * is a free-text note per device (buyout justifications, special cases), which
 * lives in lease_decisions keyed by asset_id with no decision_type. So the
 * column must allow null.
 */
class MakeLeaseDecisionTypeNullable extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('lease_decisions', 'decision_type')) {
            Schema::table('lease_decisions', function (Blueprint $table) {
                $table->string('decision_type')->nullable()->change();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('lease_decisions', 'decision_type')) {
            Schema::table('lease_decisions', function (Blueprint $table) {
                $table->string('decision_type')->nullable(false)->change();
            });
        }
    }
}
