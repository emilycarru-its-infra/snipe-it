<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: every CCA Financial lease schedule id gains the lessor's 4130-
 * account prefix (ECI20220901 → 4130-ECI20220901), across all four tables
 * that carry the reference. Idempotent — the ECI% guard matches nothing
 * once the prefix is on. The disposition grid's ?contract= deep link
 * resolves by substring, so pre-rename links keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("UPDATE assets SET lease_contract_id = CONCAT('4130-', lease_contract_id) WHERE lease_contract_id LIKE 'ECI%'");
        DB::update("UPDATE contracts SET schedule_number = CONCAT('4130-', schedule_number) WHERE schedule_number LIKE 'ECI%'");
        DB::update("UPDATE lease_decisions SET contract_reference = CONCAT('4130-', contract_reference) WHERE contract_reference LIKE 'ECI%'");
        DB::update("UPDATE order_invoices SET contract_reference = CONCAT('4130-', contract_reference) WHERE contract_reference LIKE 'ECI%'");
    }

    public function down(): void
    {
        DB::update("UPDATE assets SET lease_contract_id = SUBSTRING(lease_contract_id, 6) WHERE lease_contract_id LIKE '4130-ECI%'");
        DB::update("UPDATE contracts SET schedule_number = SUBSTRING(schedule_number, 6) WHERE schedule_number LIKE '4130-ECI%'");
        DB::update("UPDATE lease_decisions SET contract_reference = SUBSTRING(contract_reference, 6) WHERE contract_reference LIKE '4130-ECI%'");
        DB::update("UPDATE order_invoices SET contract_reference = SUBSTRING(contract_reference, 6) WHERE contract_reference LIKE '4130-ECI%'");
    }
};
