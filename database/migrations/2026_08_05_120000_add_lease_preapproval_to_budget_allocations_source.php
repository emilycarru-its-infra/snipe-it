<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the budget_allocations source enum with 'lease_preapproval'. The
 * lease-end pre-approval joins the approved budget LIVE (computed from the
 * schedules ending in the FY); posting an allocation with this source
 * overrides the live figure — the same override pattern carry_forward uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE budget_allocations MODIFY COLUMN source ENUM('forecast', 'supplemental', 'adjustment', 'carry_forward', 'lease_preapproval') NOT NULL DEFAULT 'supplemental'");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE budget_allocations MODIFY COLUMN source ENUM('forecast', 'supplemental', 'adjustment', 'carry_forward') NOT NULL DEFAULT 'supplemental'");
    }
};
