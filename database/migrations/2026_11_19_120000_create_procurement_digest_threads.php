<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_digest_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel');
            $table->json('pending');
            $table->json('cards');
            $table->unsignedInteger('next_part')->default(0);
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_digest_threads');
    }
};
