<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-email CC override, alongside the existing recipients (To) override.
 * Comma-separated addresses; null means "use the email's built-in CC list"
 * (e.g. the lease buyout request's config-seeded team list). Lets an admin
 * change who is copied on an email from Settings → Emails instead of a
 * config edit + redeploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->text('cc')->nullable()->default(null)->after('recipients');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('cc');
        });
    }
};
