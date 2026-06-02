<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable catalog of project statuses (the Numbers-sheet dropdown:
 * None…Media Resources). Colors drive the status donut, count card and
 * table labels. Seeded with ECU's sixteen; institutions can curate.
 */
class CreateExhibitStatusesTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('exhibit_statuses')) {
            Schema::create('exhibit_statuses', function (Blueprint $table) {
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

        if (DB::table('exhibit_statuses')->count() === 0) {
            $rows = [
                ['None', 'none', '#bdc3c7'],
                ['Pending', 'pending', '#95a5a6'],
                ['Need to Contact', 'need_to_contact', '#e74c3c'],
                ['Reserved', 'reserved', '#16a085'],
                ['Waitlisted', 'waitlisted', '#f39c12'],
                ['Scheduled', 'scheduled', '#2980b9'],
                ['In Progress', 'in_progress', '#3498db'],
                ['Done', 'done', '#27ae60'],
                ['Cancelled', 'cancelled', '#7f8c8d'],
                ['Self Setup', 'self_setup', '#1abc9c'],
                ['Master Student', 'master_student', '#9b59b6'],
                ['Early Setup', 'early_setup', '#2ecc71'],
                ['Ready', 'ready', '#e67e22'],
                ['Late', 'late', '#c0392b'],
                ['Undetermined', 'undetermined', '#bdc3c7'],
                ['Media Resources', 'media_resources', '#34495e'],
            ];
            foreach ($rows as $i => [$name, $slug, $color]) {
                DB::table('exhibit_statuses')->insert([
                    'name' => $name, 'slug' => $slug, 'color' => $color,
                    'sort_order' => $i, 'active' => true,
                ]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('exhibit_statuses');
    }
}
