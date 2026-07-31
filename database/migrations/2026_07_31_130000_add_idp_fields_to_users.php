<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Native identity-provider link on the user, replacing the convention of
     * pasting the Entra portal URL into Notes. idp_label is the display text
     * so the UI can show a short link instead of the raw URL.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('idp_url')->nullable()->after('website');
            $table->string('idp_label')->nullable()->after('idp_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['idp_url', 'idp_label']);
        });
    }
};
