<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminder bookkeeping for the 3-day signature-reminder cron. The
 * artisan command `snipeit:user-agreement-signature-reminders` reads
 * both columns to decide eligibility, increments `reminders_sent`,
 * and stamps `last_reminder_sent_at`.
 *
 * Backfill stays implicit: existing rows get `reminders_sent = 0`
 * and a null timestamp, which means the cron picks them up
 * immediately if their `agreement_sent` stage transition is older
 * than the configured interval. Wanted behaviour — we want long-
 * outstanding agreements to get a nudge.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_agreements', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminders_sent')->default(0)->after('signed_pdf_path');
            $table->timestamp('last_reminder_sent_at')->nullable()->after('reminders_sent');
        });
    }

    public function down(): void
    {
        Schema::table('user_agreements', function (Blueprint $table) {
            $table->dropColumn(['reminders_sent', 'last_reminder_sent_at']);
        });
    }
};
