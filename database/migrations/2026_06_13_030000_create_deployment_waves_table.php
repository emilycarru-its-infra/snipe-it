<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment waves — the planning spine. A wave is a named cohort of
 * devices being refreshed or deployed together (e.g. "FY27-28 Faculty
 * Refresh"), with a forecast arrival window and a physical deploy window.
 * Links to a deployment type, an optional target/storage Location, an
 * owner, and an optional PurchaseOrder so the operational view ties back
 * to /reports/procurement (the financial view). wave_state is the
 * high-level rollup (planned → ordered → arriving → receiving →
 * deploying → done); per-device pipeline lives on deployment_items.stage_id.
 */
class CreateDeploymentWavesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('deployment_waves')) {
            return;
        }

        Schema::create('deployment_waves', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Canonical "FY2027-28"; reuses procurement FY helpers.
            $table->string('fiscal_year')->nullable()->index();
            $table->unsignedInteger('deployment_type_id')->nullable()->index();
            $table->string('wave_state')->default('planned')->index();
            // Forecast arrival window (from orders/shipments).
            $table->date('arrival_window_start')->nullable();
            $table->date('arrival_window_end')->nullable();
            // Physical deploy window.
            $table->date('target_start_date')->nullable();
            $table->date('target_end_date')->nullable();
            // Target site + where devices are staged before deploy.
            $table->unsignedInteger('location_id')->nullable()->index();
            $table->unsignedInteger('storage_location_id')->nullable()->index();
            $table->unsignedInteger('owner_id')->nullable()->index();
            // Budget link back to procurement.
            $table->unsignedInteger('purchase_order_id')->nullable()->index();
            $table->string('color', 32)->nullable();
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->engine = 'InnoDB';
        });
    }

    public function down()
    {
        Schema::dropIfExists('deployment_waves');
    }
}
