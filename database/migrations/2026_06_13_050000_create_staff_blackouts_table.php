<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff blackouts — windows when a deploy-team member is unavailable
 * (vacation / OOO), used to schedule deploy windows around staff capacity
 * and warn on collisions. Primarily synced from M365 / Entra calendars
 * (source='graph', external_id = the Graph event id) by the
 * deployment-staff-sync function via a token-protected API endpoint;
 * source='manual' rows can be hand-added in the UI.
 */
class CreateStaffBlackoutsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('staff_blackouts')) {
            return;
        }

        Schema::create('staff_blackouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('source', 16)->default('manual')->index();
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->string('reason')->nullable();
            // Graph event id for idempotent upsert on re-sync.
            $table->string('external_id')->nullable()->index();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();
            $table->engine = 'InnoDB';
        });
    }

    public function down()
    {
        Schema::dropIfExists('staff_blackouts');
    }
}
