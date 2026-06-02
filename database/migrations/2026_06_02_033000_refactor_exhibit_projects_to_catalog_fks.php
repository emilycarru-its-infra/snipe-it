<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Points exhibit_projects at the new editable catalogs by FK
 * (exhibit_id / status_id / project_type_id), replacing the v1 string
 * columns (show / status / project_type). Backfills any existing rows by
 * matching the old string values to the seeded catalog rows, then drops
 * the strings. Safe on a fresh DB (v1 create migration runs first).
 */
class RefactorExhibitProjectsToCatalogFks extends Migration
{
    public function up()
    {
        Schema::table('exhibit_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('exhibit_projects', 'exhibit_id')) {
                $table->unsignedInteger('exhibit_id')->nullable()->index()->after('id');
            }
            if (! Schema::hasColumn('exhibit_projects', 'status_id')) {
                $table->unsignedInteger('status_id')->nullable()->index()->after('asset_id');
            }
            if (! Schema::hasColumn('exhibit_projects', 'project_type_id')) {
                $table->unsignedInteger('project_type_id')->nullable()->index()->after('status_id');
            }
        });

        // Backfill from the v1 string columns where present.
        if (Schema::hasColumn('exhibit_projects', 'show')) {
            foreach (DB::table('exhibit_projects')->get() as $p) {
                DB::table('exhibit_projects')->where('id', $p->id)->update([
                    'exhibit_id' => DB::table('exhibits')->where('name', $p->show)->value('id'),
                    'status_id' => DB::table('exhibit_statuses')->where('slug', $p->status)->value('id'),
                    'project_type_id' => $p->project_type
                        ? DB::table('exhibit_project_types')->where('slug', $p->project_type)->value('id')
                        : null,
                ]);
            }
        }

        Schema::table('exhibit_projects', function (Blueprint $table) {
            foreach (['show', 'status', 'project_type'] as $col) {
                if (Schema::hasColumn('exhibit_projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down()
    {
        Schema::table('exhibit_projects', function (Blueprint $table) {
            $table->string('show')->default('Grad Show')->after('id');
            $table->string('status')->default('pending');
            $table->string('project_type')->nullable();
        });

        Schema::table('exhibit_projects', function (Blueprint $table) {
            foreach (['exhibit_id', 'status_id', 'project_type_id'] as $col) {
                if (Schema::hasColumn('exhibit_projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
