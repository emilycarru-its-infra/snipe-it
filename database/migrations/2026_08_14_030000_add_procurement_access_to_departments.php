<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department-scoped procurement read access, toggled on the department's
 * own edit page rather than hardcoded anywhere: members of a flagged
 * department (Finance, first) can view the procurement pages by virtue
 * of membership, which the Entra sync maintains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->boolean('procurement_access')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('procurement_access');
        });
    }
};
