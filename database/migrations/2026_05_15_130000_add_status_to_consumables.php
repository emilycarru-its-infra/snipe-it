<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStatusToConsumables extends Migration
{
    public function up()
    {
        // Lifecycle status for a consumable. Defaults to 'active' so every
        // existing consumable is treated as in-use stock.
        Schema::table('consumables', function (Blueprint $table) {
            $table->string('status')->default('active')->after('requestable');
        });
    }

    public function down()
    {
        Schema::table('consumables', function (Blueprint $table) {
            if (Schema::hasColumn('consumables', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
}
