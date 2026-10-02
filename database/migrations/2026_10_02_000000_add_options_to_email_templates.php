<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-email settings beyond subject, body and recipients.
 *
 * Some emails carry behaviour of their own — whether they send at all, who
 * they are from, how long they wait. Those were environment variables, so
 * changing one was a deploy. An email declares the settings it has in
 * EmailRegistry ('options'); what an admin sets in Settings → Emails is
 * stored here by name, and anything left blank keeps the environment default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->json('options')->nullable()->default(null)->after('teams_channel');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
