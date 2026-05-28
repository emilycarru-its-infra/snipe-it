<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_eligibility', function (Blueprint $table) {
            $table->id();
            $table->string('form_slug', 64);
            $table->unsignedInteger('group_id');
            $table->timestamps();

            $table->unique(['form_slug', 'group_id']);
            $table->index('form_slug');
            $table->foreign('group_id')->references('id')->on('permission_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_eligibility');
    }
};
