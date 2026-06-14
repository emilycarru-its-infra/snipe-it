<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable catalog of deployment types — the kind of work a wave
 * represents (Refresh, New Hire, Lab/Classroom, Exhibit, Ad-hoc). Colors
 * drive the dashboard donut + count table. Mirrors exhibit_project_types;
 * institutions can rename/add their own. "Exhibit" is the bridge type so
 * the existing /reports/exhibit board lives under the deployments umbrella.
 */
class CreateDeploymentTypesTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('deployment_types')) {
            Schema::create('deployment_types', function (Blueprint $table) {
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

        if (DB::table('deployment_types')->count() === 0) {
            $rows = [
                ['Refresh', 'refresh', '#2980b9'],
                ['New Hire', 'new_hire', '#27ae60'],
                ['Lab / Classroom', 'lab_classroom', '#8e44ad'],
                ['Exhibit', 'exhibit', '#e67e22'],
                ['Ad-hoc', 'ad_hoc', '#95a5a6'],
            ];
            foreach ($rows as $i => [$name, $slug, $color]) {
                DB::table('deployment_types')->insert([
                    'name' => $name, 'slug' => $slug, 'color' => $color,
                    'sort_order' => $i, 'active' => true,
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('deployment_types');
    }
}
