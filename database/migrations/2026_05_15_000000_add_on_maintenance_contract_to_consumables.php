<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOnMaintenanceContractToConsumables extends Migration
{
    public function up()
    {
        Schema::table('consumables', function (Blueprint $table) {
            $table->boolean('on_maintenance_contract')->default(false)->after('requestable');
        });
    }

    public function down()
    {
        Schema::table('consumables', function (Blueprint $table) {
            if (Schema::hasColumn('consumables', 'on_maintenance_contract')) {
                $table->dropColumn('on_maintenance_contract');
            }
        });
    }
}
