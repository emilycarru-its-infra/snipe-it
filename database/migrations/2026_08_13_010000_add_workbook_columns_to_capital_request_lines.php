<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workbook's remaining columns for a manually entered New Ask line —
 * the capital request renders one table, so a typed line carries the same
 * facets a derived refresh line does: which area it serves, what kind of
 * device it is, and what lease terms it should land on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capital_request_lines', function (Blueprint $table) {
            $table->string('area')->nullable()->after('fiscal_year');
            $table->string('type')->nullable()->after('need');
            $table->string('preference')->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('capital_request_lines', function (Blueprint $table) {
            $table->dropColumn(['area', 'type', 'preference']);
        });
    }
};
