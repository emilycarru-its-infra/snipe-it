<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Second-pass field-group adjustments on top of seed_asset_field_groups:
 *  - Platform moves into Specs, Device Management Service into Inventory, which
 *    empties the Management group — so Management is removed.
 *  - "Identity" is about device identifiers (Entra/Intune/Object IDs), not
 *    personal identity: rename to "Identifiers" and swap the fingerprint icon
 *    for a barcode.
 * Idempotent: matches groups by slug and fields by name.
 */
class RegroupAssetDetailFields extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('field_groups') || ! Schema::hasColumn('custom_fields', 'field_group_id')) {
            return;
        }

        $slugToId = DB::table('field_groups')->pluck('id', 'slug');

        // Platform -> Specs
        if (isset($slugToId['specs'])) {
            DB::table('custom_fields')->where('name', 'Platform')
                ->update(['field_group_id' => $slugToId['specs']]);
        }
        // Device Management Service -> Inventory
        if (isset($slugToId['inventory'])) {
            DB::table('custom_fields')->where('name', 'Device Management Service')
                ->update(['field_group_id' => $slugToId['inventory']]);
        }

        // Management is now empty — null any stragglers, then drop the group.
        if (isset($slugToId['management'])) {
            DB::table('custom_fields')->where('field_group_id', $slugToId['management'])
                ->update(['field_group_id' => null]);
            DB::table('field_groups')->where('slug', 'management')->delete();
        }

        // Identity -> Identifiers, barcode icon (device IDs, not biometrics).
        DB::table('field_groups')->where('slug', 'identity')->update([
            'name' => 'Identifiers',
            'icon' => 'fas fa-barcode',
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        if (! Schema::hasTable('field_groups')) {
            return;
        }
        DB::table('field_groups')->where('slug', 'identity')->update([
            'name' => 'Identity',
            'icon' => 'fas fa-fingerprint',
            'updated_at' => now(),
        ]);
        // Management isn't recreated and Platform / DMS reassignments aren't
        // reversed — the forward state is the intended one.
    }
}
