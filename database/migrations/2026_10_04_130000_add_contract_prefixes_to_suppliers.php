<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which lessor owns a lease contract was decided from its number prefix in
 * code. The prefixes now live on the lessor's Supplier record, read by
 * LessorGuard, and are set per deployment (Suppliers → edit, or the
 * suppliers API), never shipped in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('contract_prefixes')->nullable()->after('pickup_emails');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('contract_prefixes');
        });
    }
};
