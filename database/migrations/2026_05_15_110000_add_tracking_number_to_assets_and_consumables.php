<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTrackingNumberToAssetsAndConsumables extends Migration
{
    public function up()
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->default(null)->after('order_number');
            $table->string('tracking_carrier')->nullable()->default(null)->after('tracking_number');
        });

        Schema::table('consumables', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->default(null)->after('order_number');
            $table->string('tracking_carrier')->nullable()->default(null)->after('tracking_number');
        });
    }

    public function down()
    {
        Schema::table('assets', function (Blueprint $table) {
            foreach (['tracking_number', 'tracking_carrier'] as $column) {
                if (Schema::hasColumn('assets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('consumables', function (Blueprint $table) {
            foreach (['tracking_number', 'tracking_carrier'] as $column) {
                if (Schema::hasColumn('consumables', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
