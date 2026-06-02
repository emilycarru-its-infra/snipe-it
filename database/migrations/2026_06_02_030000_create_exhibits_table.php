<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable catalog of exhibits/shows (Grad Show, MFA Thesis, Foundation,
 * Type, …). exhibit_projects points here by FK so a show can be renamed
 * or recolored without breaking rows — and a different institution can
 * define its own exhibits. Seeded with ECU's four; idempotent.
 */
class CreateExhibitsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('exhibits')) {
            Schema::create('exhibits', function (Blueprint $table) {
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

        if (DB::table('exhibits')->count() === 0) {
            $rows = [
                ['Grad Show', 'grad-show', '#27ae60'],
                ['MFA Thesis', 'mfa-thesis', '#2980b9'],
                ['Foundation Show', 'foundation-show', '#e67e22'],
                ['Type Show', 'type-show', '#8e44ad'],
            ];
            foreach ($rows as $i => [$name, $slug, $color]) {
                DB::table('exhibits')->insert([
                    'name' => $name, 'slug' => $slug, 'color' => $color,
                    'sort_order' => $i, 'active' => true,
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('exhibits');
    }
}
