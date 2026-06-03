<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-email recipient override for the report/admin emails. Comma-separated
 * addresses; null means "use the global Setting::alert_email list" (today's
 * behaviour). Lets an admin route each report to its own audience instead of
 * everything landing in one ambiguous alert inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->text('recipients')->nullable()->default(null)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('recipients');
        });
    }
};
