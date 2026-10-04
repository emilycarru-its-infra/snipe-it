<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who books a lessor's end-of-lease pickups is often not who handles the
 * account: the pickup request is addressed to that person, with the account
 * contacts copied. Comma-separated, like lease_emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('pickup_emails')->nullable()->after('lease_emails');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('pickup_emails');
        });
    }
};
