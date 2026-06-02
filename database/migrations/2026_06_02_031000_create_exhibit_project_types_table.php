<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable catalog of project types (Looping Video, Website, …). Colors
 * drive the dashboard donut + count table. Seeded with ECU's seven from
 * the Numbers sheet; institutions can rename/add their own.
 */
class CreateExhibitProjectTypesTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('exhibit_project_types')) {
            Schema::create('exhibit_project_types', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('color', 32)->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        if (DB::table('exhibit_project_types')->count() === 0) {
            $rows = [
                ['Looping Video', 'looping_video', '#f1c40f'],
                ['Website', 'website', '#1abc9c'],
                ['Specialized App', 'specialized_app', '#f39c12'],
                ['Figma', 'figma', '#e74c3c'],
                ['Audio', 'audio', '#8e44ad'],
                ['Looping PDF', 'looping_pdf', '#2ecc71'],
                ['Other', 'other', '#95a5a6'],
            ];
            foreach ($rows as $i => [$name, $slug, $color]) {
                DB::table('exhibit_project_types')->insert([
                    'name' => $name, 'slug' => $slug, 'color' => $color,
                    'sort_order' => $i, 'active' => true,
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('exhibit_project_types');
    }
}
