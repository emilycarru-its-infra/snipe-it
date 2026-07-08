<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Address" is a network address (not a mailing address), so it belongs in the
 * Networking group, not Inventory. Idempotent: matched by field name + group slug.
 */
class MoveAddressFieldToNetworking extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('field_groups') || ! Schema::hasColumn('custom_fields', 'field_group_id')) {
            return;
        }
        $networkingId = DB::table('field_groups')->where('slug', 'networking')->value('id');
        if ($networkingId) {
            DB::table('custom_fields')->where('name', 'Address')->update(['field_group_id' => $networkingId]);
        }
    }

    public function down()
    {
        if (! Schema::hasTable('field_groups')) {
            return;
        }
        $inventoryId = DB::table('field_groups')->where('slug', 'inventory')->value('id');
        if ($inventoryId) {
            DB::table('custom_fields')->where('name', 'Address')->update(['field_group_id' => $inventoryId]);
        }
    }
}
