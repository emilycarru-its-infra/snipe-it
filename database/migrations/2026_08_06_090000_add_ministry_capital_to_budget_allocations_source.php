<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the budget_allocations source enum with 'ministry_capital' —
 * funding that arrives from ministry capital outside the pre-allocated
 * fiscal-year pot. Booking it as its own source keeps the injection
 * visible in the ledger and lets the order it funds flow through the
 * normal requisition → PO → committed pipeline without dragging the
 * operating Remaining figure negative.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE budget_allocations MODIFY COLUMN source ENUM('forecast', 'supplemental', 'adjustment', 'carry_forward', 'lease_preapproval', 'ministry_capital') NOT NULL DEFAULT 'supplemental'");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE budget_allocations MODIFY COLUMN source ENUM('forecast', 'supplemental', 'adjustment', 'carry_forward', 'lease_preapproval') NOT NULL DEFAULT 'supplemental'");
    }
};
