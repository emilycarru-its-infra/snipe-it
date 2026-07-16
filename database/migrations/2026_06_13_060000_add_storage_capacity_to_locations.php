<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional storage_capacity (slot count) to locations so a
 * Location can double as a staging area for not-yet-deployed devices.
 * The deployments storage view shows capacity vs the live staged count
 * (deployment_items.storage_location_id where not yet deployed). Additive
 * and nullable — locations that aren't storage areas leave it empty.
 */
class AddStorageCapacityToLocations extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('locations', 'storage_capacity')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->integer('storage_capacity')->nullable()->after('notes');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('locations', 'storage_capacity')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('storage_capacity');
            });
        }
    }
}
