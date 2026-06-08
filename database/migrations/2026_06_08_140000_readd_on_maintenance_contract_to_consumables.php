<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heals schema drift on environments where the original
 * 2026_05_15_000000_add_on_maintenance_contract_to_consumables migration is
 * recorded as run but the column is actually missing (prod was seeded from a
 * baseline that carried the migrations row without the column). The original
 * up() is not idempotent, so it never self-corrects; this guarded re-add does.
 *
 * Without the column, every web consumable save 500s — ConsumablesController@update
 * always writes `on_maintenance_contract`, so editing a consumable (or the qty
 * stepper, which saves through the same model) throws
 * SQLSTATE[42S22] Unknown column 'on_maintenance_contract'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consumables', 'on_maintenance_contract')) {
            Schema::table('consumables', function (Blueprint $table) {
                $table->boolean('on_maintenance_contract')->default(false)->after('requestable');
            });
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: the column is owned by the original
        // add_on_maintenance_contract_to_consumables migration. Rolling this
        // healer back must not drop a column the app depends on.
    }
};
