<?php

use App\Models\Contract;
use App\Models\License;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Establish a hard 1:N relationship from licenses to contracts.
 *
 * ECU's rule: every software license must originate from a procurement
 * contract. Today's prod state is 58 licenses, 0 linked via the
 * `contract_license` pivot — so the back-fill is trivial: assign every
 * orphan to a system-owned "Unattributed" contract that admins reassign
 * to the real one over time.
 *
 * The pivot table `contract_license` stays in place for now; PR-C
 * removes it once this FK has baked on prod.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unattributed = $this->ensureUnattributedContract();

        Schema::table('licenses', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable()->after('supplier_id');
            $table->index('contract_id', 'licenses_contract_id_idx');
        });

        DB::table('licenses')
            ->whereNull('contract_id')
            ->update(['contract_id' => $unattributed->id]);

        Schema::table('licenses', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable(false)->change();
            $table->foreign('contract_id', 'licenses_contract_id_foreign')
                ->references('id')->on('contracts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropForeign('licenses_contract_id_foreign');
            $table->dropIndex('licenses_contract_id_idx');
            $table->dropColumn('contract_id');
        });
    }

    /**
     * Find-or-create the system "Unattributed" contract. Marked
     * synthesized so the TDX reconciler ignores it and source=manual so
     * the contracts UI shows it as a non-TDX row. Idempotent.
     */
    private function ensureUnattributedContract(): Contract
    {
        $existing = Contract::query()
            ->whereNull('tdx_id')
            ->where('name', 'Unattributed')
            ->first();

        if ($existing) {
            return $existing;
        }

        // Pick any seeded admin to attribute the row to; fall back to
        // null on fresh-DB installs (e.g. CI test runs) where no users
        // exist yet — `created_by` is nullable so this is safe.
        $owner = User::orderBy('id')->first();

        $contract = new Contract;
        $contract->name             = 'Unattributed';
        $contract->contract_number  = 'UNATTRIBUTED';
        $contract->is_synthesized   = true;
        $contract->is_active        = true;
        $contract->workflow_status  = 'active';
        $contract->source           = 'manual';
        $contract->description      = 'System contract holding licenses that have no originating procurement contract recorded. Reassign each license to its real contract over time.';
        $contract->created_by       = $owner?->id;
        $contract->save();

        return $contract;
    }
};
