<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which lessor owns a lease contract was decided from its number prefix in
 * code. The prefixes now live on the lessor's Supplier record, read by
 * LessorGuard. Seeds the prefixes the code used, so nothing changes on
 * deploy; a lessor not present (a fresh database) is left alone.
 */
return new class extends Migration
{
    /** Supplier name => comma-separated prefixes. */
    private const SEED = [
        'CCA Financial' => '4130-,ECI',
        'CSI Leasing' => '100000-',
    ];

    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('contract_prefixes')->nullable()->after('pickup_emails');
        });

        $this->seed();
    }

    /** Stamp the seed onto the named suppliers that exist and have none yet. */
    public function seed(): void
    {
        foreach (self::SEED as $name => $prefixes) {
            DB::table('suppliers')
                ->where('name', $name)
                ->whereNull('deleted_at')
                ->whereNull('contract_prefixes')
                ->update(['contract_prefixes' => $prefixes]);
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('contract_prefixes');
        });
    }
};
