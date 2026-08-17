<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waves built from existing equipment (relocations, exhibit allocations)
 * end with the devices somewhere new, not with new devices. A type that
 * declares moves_devices makes that the wave's completion behavior: a
 * terminal stage moves each item's asset to the wave's target location,
 * the same bridge maps_to_status_id already provides for status.
 *
 * Seeds a Relocation type alongside the existing Exhibit one, both with
 * the flag on — the two shapes this was built for (room-to-room moves,
 * show equipment allocation).
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('deployment_types', function (Blueprint $table) {
            $table->boolean('moves_devices')->default(false)->after('color');
        });

        DB::table('deployment_types')->where('slug', 'exhibit')->update(['moves_devices' => true]);

        if (! DB::table('deployment_types')->where('slug', 'relocation')->exists()) {
            DB::table('deployment_types')->insert([
                'name' => 'Relocation',
                'slug' => 'relocation',
                'color' => '#16a085',
                'moves_devices' => true,
                'sort_order' => (int) DB::table('deployment_types')->max('sort_order') + 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        Schema::table('deployment_types', function (Blueprint $table) {
            $table->dropColumn('moves_devices');
        });
    }
};
