<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-email delivery routing, alongside the existing subject/body/recipients
 * overrides.
 *
 * Internal notifications are moving off email and into Teams cards, but which
 * ones — and on which channel — is an operational decision, not a deploy. A
 * null delivery means "use the registry default", so the table stays sparse in
 * the same way it already is for the other overrides.
 *
 * teams_channel holds a channel *key* ("devices", "procurement", …), never a
 * webhook URL. The URLs are app settings resolved from Key Vault; the database
 * never sees them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('delivery', 16)->nullable()->default(null)->after('cc');
            $table->string('teams_channel', 32)->nullable()->default(null)->after('delivery');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn(['delivery', 'teams_channel']);
        });
    }
};
