<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Receiving was last told about a pickup, and which booking they were
 * told about. A pickup the lessor moves (new date, window or load number)
 * gets a fresh heads-up; saving the same booking again does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_pickups', function (Blueprint $table) {
            $table->dateTime('receiving_notified_at')->nullable()->after('scheduled_window');
            $table->string('receiving_notified_for', 191)->nullable()->after('receiving_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('lease_pickups', function (Blueprint $table) {
            $table->dropColumn(['receiving_notified_at', 'receiving_notified_for']);
        });
    }
};
