<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a wave was announced to the people in it.
 *
 * A wave used to become real when devices arrived, but for a program like the
 * Faculty Laptop Program the first real event is the email: the annual note that
 * tells faculty the cycle is open and asks them to choose. Nothing else happens
 * until it goes out, and it going out is what starts the wave — so it is a date
 * on the wave rather than something remembered from a sent-items folder.
 */
class AddAnnouncedAtToDeploymentWaves extends Migration
{
    public function up()
    {
        Schema::table('deployment_waves', function (Blueprint $table) {
            if (! Schema::hasColumn('deployment_waves', 'announced_at')) {
                $table->timestamp('announced_at')->nullable()->after('wave_state');
            }
        });
    }

    public function down()
    {
        Schema::table('deployment_waves', function (Blueprint $table) {
            $table->dropColumn('announced_at');
        });
    }
}
