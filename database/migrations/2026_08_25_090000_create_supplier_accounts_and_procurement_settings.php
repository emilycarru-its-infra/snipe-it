<?php

use App\Services\SupplierAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier accounts and the lease-schedule cadence become data.
 *
 * Both were PHP constants, so changing an account number or moving the
 * quarterly schedule anchor meant a pull request, a review, a build and a
 * deploy — for a fact that changes on the vendor's timetable, not ours. A
 * new schedule pair opens every three months, which guaranteed the constant
 * would be wrong four times a year.
 *
 * Seeded from the constants so nothing changes on the day this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_accounts')) {
            Schema::create('supplier_accounts', function (Blueprint $table) {
                $table->id();
                // Whose accounts these are. Nullable because the seed rows
                // predate any guarantee that the supplier exists yet.
                $table->unsignedInteger('supplier_id')->nullable()->index();
                $table->string('key', 64)->unique();
                $table->string('number', 64);
                $table->string('purpose', 191);
                $table->string('kind', 32);            // purchase | lease
                $table->string('scope', 32);           // admin | curriculum
                $table->string('payee', 32);           // ecu | csi
                $table->string('schedule_type', 32)->nullable(); // return | own
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('procurement_settings')) {
            Schema::create('procurement_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        // The seeded accounts are the reseller's, so attach them to that
        // supplier where the row exists. A fresh install with no suppliers
        // yet leaves them unattached rather than inventing one.
        $supplierId = DB::table('suppliers')->where('name', 'like', 'CDW%')->value('id');

        $sort = 0;
        foreach (SupplierAccounts::SEED_ACCOUNTS as $key => $account) {
            DB::table('supplier_accounts')->updateOrInsert(['key' => $key], [
                'supplier_id' => $supplierId,
                'number' => $account['number'],
                'purpose' => $account['purpose'],
                'kind' => $account['kind'],
                'scope' => $account['scope'],
                'payee' => $account['payee'],
                'schedule_type' => $account['schedule'],
                'sort' => $sort++,
                'active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        foreach ([
            'lease_master_contract' => '301452',
            'lease_anchor_number' => '9',
            'lease_anchor_quarter_start' => '2026-07-01',
        ] as $key => $value) {
            DB::table('procurement_settings')->updateOrInsert(['key' => $key], [
                'value' => $value,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_accounts');
        Schema::dropIfExists('procurement_settings');
    }
};
