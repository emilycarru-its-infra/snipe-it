<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locations can be marked as storage/holding rooms so the Storage page
 * shows them as standing tables — added and removed from the page
 * itself, the way the crew actually manages shelves.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('show_in_storage')->default(false)->after('storage_capacity');
        });
    }

    public function down()
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('show_in_storage');
        });
    }
};
