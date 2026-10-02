<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A set of leased devices going back to their lessor in one truck.
 *
 * The decommissioning lane already groups what left by decommission date,
 * but only after the fact. Everything before that — asking the lessor for a
 * pickup, the load number they answer with, the window they schedule — lived
 * in a mail thread that started with a count and no device list, so the same
 * questions came back every time. This table is that stretch: the request
 * names every device up front, and the lessor's answer is recorded against
 * it rather than in a reply-all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_pickups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('lessor_id')->nullable()->index();
            $table->unsignedInteger('requested_by')->nullable();
            $table->string('status', 24)->default('requested')->index();
            $table->dateTime('requested_at')->nullable();
            // Free text: "Tuesday or Wednesday next week" is how it is asked.
            $table->string('preferred_dates')->nullable();
            // The lessor's reference for the run; boxes sent after it reuse it.
            $table->string('load_number', 64)->nullable();
            $table->date('scheduled_date')->nullable();
            $table->string('scheduled_window', 64)->nullable();
            $table->date('picked_up_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lease_pickup_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lease_pickup_id')->index();
            $table->unsignedInteger('asset_id')->index();
            $table->timestamps();
            $table->unique(['lease_pickup_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_pickup_assets');
        Schema::dropIfExists('lease_pickups');
    }
};
