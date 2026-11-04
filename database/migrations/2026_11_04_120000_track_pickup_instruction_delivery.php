<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_agreements', function (Blueprint $table) {
            $table->timestamp('pickup_instructions_sent_at')->nullable();
            $table->timestamp('pickup_instructions_sending_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_agreements', function (Blueprint $table) {
            $table->dropColumn(['pickup_instructions_sent_at', 'pickup_instructions_sending_at']);
        });
    }
};
