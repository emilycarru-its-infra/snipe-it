<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_agreements', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_agreements', function (Blueprint $table) {
            $table->dropColumn('terms_accepted_at');
        });
    }
};
