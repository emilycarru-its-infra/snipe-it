<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a lease schedule to the contract it becomes once finalized, so the
 * signing queue can jump to the live contract and intake can tell whether a
 * schedule has already been finalized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable()->after('annexure_a_path');
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lease_schedules', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropColumn('contract_id');
        });
    }
};
