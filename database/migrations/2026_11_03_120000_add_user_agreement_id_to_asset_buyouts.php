<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a buyout to the purchase agreement its buyer signs.
 *
 * The signed agreement is what payroll acts on, and the buyout is what knows
 * when it is needed, so the two have to be one chain: the buyer agrees, the
 * agreement goes out for signature, and the signature sends it to payroll.
 * Without the link, nothing creates the agreement, and a signed one never
 * reaches payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_buyouts', function (Blueprint $table) {
            $table->unsignedInteger('user_agreement_id')->nullable()->index()->after('buyer_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_buyouts', function (Blueprint $table) {
            $table->dropColumn('user_agreement_id');
        });
    }
};
