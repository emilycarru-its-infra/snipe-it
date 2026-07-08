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
                // foreignId => unsignedBigInteger, matching field_groups.id()
                // (an unsignedInteger here would be type-incompatible). The FK
                // nulls the reference if a group is deleted, so a field can never
                // point at a missing group.
                $table->foreignId('field_group_id')->nullable()
                    ->constrained('field_groups')->nullOnDelete();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('custom_fields', 'field_group_id')) {
            Schema::table('custom_fields', function (Blueprint $table) {
                // Drop the FK constraint before the column, or MySQL rollback fails.
                $table->dropConstrainedForeignId('field_group_id');
            });
        }
    }
}
