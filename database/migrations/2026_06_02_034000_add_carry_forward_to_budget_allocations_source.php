<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `budget_allocations.source` is a MySQL ENUM. Widen it to allow
 * 'carry_forward' so a prior fiscal year's unspent budget can be posted
 * as its own allocation source. On non-MySQL drivers (e.g. SQLite in
 * tests) the column is a plain string, so no change is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE budget_allocations MODIFY COLUMN source ENUM('forecast', 'supplemental', 'adjustment', 'carry_forward') NOT NULL DEFAULT 'supplemental'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE budget_allocations MODIFY COLUMN source ENUM('forecast', 'supplemental', 'adjustment') NOT NULL DEFAULT 'supplemental'");
        }
    }
};
