<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lessor as a first-class, data-driven attribute of a leased device, separate
 * from supplier. supplier_id = who sold the device (CDW, Microserve, …);
 * lessor_id = who financed the lease (the lessor). Both reference the existing
 * suppliers table — a lessor is just a Supplier record playing the lessor role,
 * so it already carries name + contact info + its own UI.
 *
 * This also seeds the lessor Supplier records and backfills lessor_id from the
 * current contract-ID-prefix mapping ONE TIME (301452-* → CSI Leasing, ECI* /
 * 4130-* → CCA Financial). This is the last place those vendor names appear in
 * code; afterwards everything reads the lessor_id FK and the lease-ingest
 * functions keep it populated.
 */
class AddLessorIdToAssets extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('assets', 'lessor_id')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->unsignedInteger('lessor_id')->nullable()->after('supplier_id')->index();
            });
        }

        // Seed the two lessor Supplier records (idempotent).
        $ccaId = DB::table('suppliers')->where('name', 'CCA Financial')->value('id');
        if (! $ccaId) {
            $ccaId = DB::table('suppliers')->insertGetId(['name' => 'CCA Financial', 'created_at' => now(), 'updated_at' => now()]);
        }
        $csiId = DB::table('suppliers')->where('name', 'CSI Leasing')->value('id');
        if (! $csiId) {
            $csiId = DB::table('suppliers')->insertGetId(['name' => 'CSI Leasing', 'created_at' => now(), 'updated_at' => now()]);
        }

        // Backfill lessor_id from the Lease Contract ID custom field, looked up
        // by name so it works whatever the db_column happens to be per env.
        $col = DB::table('custom_fields')->where('name', 'Lease Contract ID')->value('db_column');
        if ($col && Schema::hasColumn('assets', $col)) {
            DB::table('assets')->where($col, 'like', '301452-%')->update(['lessor_id' => $csiId]);
            DB::table('assets')->where(function ($q) use ($col) {
                $q->where($col, 'like', 'ECI%')->orWhere($col, 'like', '4130%');
            })->update(['lessor_id' => $ccaId]);
        }
    }

    public function down()
    {
        if (Schema::hasColumn('assets', 'lessor_id')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->dropColumn('lessor_id');
            });
        }
    }
}
