<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A wave can carry the form its people are invited through — the faculty
 * program's intake form is one of what will be several, and the wave is
 * where a form belongs: the announcement's form link resolves from the
 * wave instead of a hardcoded slug. Waves already announced under the
 * faculty program get that slug backfilled so nothing changes for them.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('deployment_waves', function (Blueprint $table) {
            $table->string('form_key', 191)->nullable()->after('purchase_order_id');
        });

        DB::table('deployment_waves')
            ->whereNotNull('announced_at')
            ->update(['form_key' => 'faculty-program']);
    }

    public function down()
    {
        Schema::table('deployment_waves', function (Blueprint $table) {
            $table->dropColumn('form_key');
        });
    }
};
