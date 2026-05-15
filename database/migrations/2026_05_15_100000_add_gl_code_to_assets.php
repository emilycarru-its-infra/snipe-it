<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGlCodeToAssets extends Migration
{
    public function up()
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('gl_code')->nullable()->default(null)->after('order_number');
        });
    }

    public function down()
    {
        Schema::table('assets', function (Blueprint $table) {
            if (Schema::hasColumn('assets', 'gl_code')) {
                $table->dropColumn('gl_code');
            }
        });
    }
}
