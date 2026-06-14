<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable catalog of deployment stages — the per-device intake pipeline,
 * a first-class replacement for the ad-hoc "New (*)" Snipe status labels
 * (New (Planned) … New (Provisioned)). Seeded with that exact pipeline
 * plus a terminal "Deployed". `is_terminal` marks the device graduated;
 * `maps_to_status_id` optionally links a stage to a real status_label so
 * advancing a device's stage can flip its Snipe status (e.g. Provisioned
 * → a deployable label). Colors drive the stage donut + board labels.
 */
class CreateDeploymentStagesTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('deployment_stages')) {
            Schema::create('deployment_stages', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('color', 32)->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_terminal')->default(false);
                // Optional bridge to a Snipe status_label; advancing to this
                // stage can set the asset's status_id to match.
                $table->unsignedInteger('maps_to_status_id')->nullable()->index();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        if (DB::table('deployment_stages')->count() === 0) {
            // [name, slug, color, is_terminal]
            $rows = [
                ['Planned', 'planned', '#95a5a6', false],
                ['Ordered', 'ordered', '#2980b9', false],
                ['Arrived', 'arrived', '#16a085', false],
                ['Inventoried', 'inventoried', '#f39c12', false],
                ['Provisioned', 'provisioned', '#3498db', false],
                ['Deployed', 'deployed', '#27ae60', true],
            ];
            foreach ($rows as $i => [$name, $slug, $color, $terminal]) {
                DB::table('deployment_stages')->insert([
                    'name' => $name, 'slug' => $slug, 'color' => $color,
                    'sort_order' => $i, 'is_terminal' => $terminal, 'active' => true,
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('deployment_stages');
    }
}
