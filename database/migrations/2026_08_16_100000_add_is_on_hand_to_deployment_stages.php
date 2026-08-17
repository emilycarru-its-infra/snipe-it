<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which stages mean the device is physically in our hands.
 *
 * The stage pipeline runs Planned → Ordered → Arrived → Inventoried →
 * Provisioned → Deployed, and only the middle three describe a box that is
 * actually sitting on a shelf. A planned refresh is a spreadsheet row and an
 * ordered device is on a truck; counting either as occupying storage made the
 * storage view a list of everything the department intends to do rather than
 * what is in the room. `is_terminal` could not answer this — it only says the
 * device has graduated, so it separates Deployed from the other five and
 * leaves Planned and Ordered looking identical to Arrived.
 */
class AddIsOnHandToDeploymentStages extends Migration
{
    /** Stages where the device is physically here. */
    private const ON_HAND = ['arrived', 'inventoried', 'provisioned'];

    public function up()
    {
        if (! Schema::hasTable('deployment_stages')) {
            return;
        }

        if (! Schema::hasColumn('deployment_stages', 'is_on_hand')) {
            Schema::table('deployment_stages', function (Blueprint $table) {
                $table->boolean('is_on_hand')->default(false)->after('is_terminal');
            });
        }

        DB::table('deployment_stages')
            ->whereIn('slug', self::ON_HAND)
            ->update(['is_on_hand' => true]);
    }

    public function down()
    {
        if (Schema::hasColumn('deployment_stages', 'is_on_hand')) {
            Schema::table('deployment_stages', function (Blueprint $table) {
                $table->dropColumn('is_on_hand');
            });
        }
    }
}
