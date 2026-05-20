<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumables_asset_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consumable_id');
            $table->unsignedBigInteger('asset_model_id');
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
