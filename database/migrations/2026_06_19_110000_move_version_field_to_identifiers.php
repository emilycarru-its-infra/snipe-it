<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Version" is a device identifier, not a hardware spec — move it from the
 * Specs group into Identifiers (slug `identity`, displayed as "Identifiers").
 * Idempotent: matches the group by slug and the field by name.
 */
class MoveVersionFieldToIdentifiers extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('field_groups') || ! Schema::hasColumn('custom_fields', 'field_group_id')) {
            return;
        }

        $identifiersId = DB::table('field_groups')->where('slug', 'identity')->value('id');
        if ($identifiersId) {
            DB::table('custom_fields')->where('name', 'Version')
                ->update(['field_group_id' => $identifiersId]);
        }
    }

    public function down()
    {
        if (! Schema::hasTable('field_groups') || ! Schema::hasColumn('custom_fields', 'field_group_id')) {
            return;
        }

        $specsId = DB::table('field_groups')->where('slug', 'specs')->value('id');
        if ($specsId) {
            DB::table('custom_fields')->where('name', 'Version')
                ->update(['field_group_id' => $specsId]);
        }
    }
}
