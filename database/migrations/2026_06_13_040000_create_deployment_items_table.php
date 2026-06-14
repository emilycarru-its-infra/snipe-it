<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment items — one row per device in a wave (the unit of work).
 * Carries the device through the stage pipeline (deployment_stages),
 * links the outgoing EOL device (replaces_asset_id) to the incoming one
 * (asset_id, once it exists) and to its procurement line (order_item_id,
 * mirroring OrderItem.replaces_asset_id). model_id holds the planned
 * replacement model before the asset is created. Tracks recipient,
 * assigned tech, target/actual deploy dates, and staging location.
 */
class CreateDeploymentItemsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('deployment_items')) {
            return;
        }

        Schema::create('deployment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('wave_id')->index();
            // The new device (once it exists in Snipe) and the EOL device
            // it replaces; either may be null while still planned.
            $table->unsignedInteger('asset_id')->nullable()->index();
            $table->unsignedInteger('replaces_asset_id')->nullable()->index();
            // Procurement line this device arrives on.
            $table->unsignedInteger('order_item_id')->nullable()->index();
            // Planned replacement model before the asset is created.
            $table->unsignedInteger('model_id')->nullable()->index();
            $table->unsignedInteger('stage_id')->nullable()->index();
            // Recipient (who gets it) + tech (who deploys it).
            $table->unsignedInteger('assigned_user_id')->nullable()->index();
            $table->unsignedInteger('assigned_tech_id')->nullable()->index();
            $table->unsignedInteger('storage_location_id')->nullable()->index();
            $table->date('target_deploy_date')->nullable();
            $table->dateTime('deployed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->engine = 'InnoDB';
        });
    }

    public function down()
    {
        Schema::dropIfExists('deployment_items');
    }
}
