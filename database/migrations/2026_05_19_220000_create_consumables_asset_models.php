<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snipe's older tables (consumables, models) declare their primary
        // key with $table->increments('id') — an unsigned INT, not BIGINT.
        // The foreign key column types here must match exactly, or MySQL
        // refuses the constraint with error 1215.
        Schema::create('consumables_asset_models', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('consumable_id');
            $table->unsignedInteger('asset_model_id');
            $table->timestamps();

            $table->foreign('consumable_id')->references('id')->on('consumables')->onDelete('cascade');
            $table->foreign('asset_model_id')->references('id')->on('models')->onDelete('cascade');
            $table->unique(['consumable_id', 'asset_model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumables_asset_models');
    }
};
