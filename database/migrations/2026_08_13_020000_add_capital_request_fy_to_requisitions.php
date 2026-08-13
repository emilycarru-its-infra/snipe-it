<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which capital request a requisition was drafted from, when it was. The
 * request's REQM/PO columns populate back ONLY through this explicit
 * lineage — matching by product attached the lease-refresh MacBook Airs
 * to the Foundation labs REQM purely because both bought Airs, which is
 * a coincidence, not a connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('capital_request_fy', 16)->nullable()->index()->after('fiscal_year');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('capital_request_fy');
        });
    }
};
