<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable taxonomy of field groups (Specs, Lease & Procurement, Identity,
 * Metadata). Custom fields map to a group via custom_fields.field_group_id;
 * the asset detail view renders one box per group instead of a single flat
 * list. `color`/`icon` drive the box header; `collapsed_by_default` hides
 * low-value groups (e.g. Metadata) behind a disclosure toggle.
 */
class CreateFieldGroupsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('field_groups')) {
            Schema::create('field_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('color', 32)->nullable();
                $table->string('icon')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('collapsed_by_default')->default(false);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        if (DB::table('field_groups')->count() === 0) {
            $rows = [
                ['Specs', 'specs', '#2980b9', 'fas fa-microchip', false],
                ['Lease & Procurement', 'lease_procurement', '#16a085', 'fas fa-file-invoice-dollar', false],
                ['Identity', 'identity', '#8e44ad', 'fas fa-fingerprint', false],
                ['Metadata', 'metadata', '#7f8c8d', 'fas fa-database', true],
            ];
            foreach ($rows as $i => [$name, $slug, $color, $icon, $collapsed]) {
                DB::table('field_groups')->insert([
                    'name' => $name,
                    'slug' => $slug,
                    'color' => $color,
                    'icon' => $icon,
                    'sort_order' => $i,
                    'collapsed_by_default' => $collapsed,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('field_groups');
    }
}
