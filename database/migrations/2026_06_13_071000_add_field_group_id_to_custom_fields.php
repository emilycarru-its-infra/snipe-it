<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Map each custom field to a field group (nullable — ungrouped fields fall
 * into an "Other" box on the asset detail view). Per-field rather than per
 * fieldset-pivot so a field's group is consistent everywhere it's used.
 */
class AddFieldGroupIdToCustomFields extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('custom_fields', 'field_group_id')) {
            Schema::table('custom_fields', function (Blueprint $table) {
                $table->unsignedInteger('field_group_id')->nullable()->index();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('custom_fields', 'field_group_id')) {
            Schema::table('custom_fields', function (Blueprint $table) {
                $table->dropColumn('field_group_id');
            });
        }
    }
}
