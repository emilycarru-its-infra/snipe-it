<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes the lease / purchasing cluster from ad-hoc Snipe-IT custom fields
 * (generated `_snipeit_*` columns, populated by the Azure Functions) to NATIVE
 * typed columns on the assets table. This is PR 1 of an incremental migration:
 * additive columns only. A model shim (MirrorsLeaseFields) dual-writes the
 * custom-field values into these columns on every save, and a companion
 * backfill migration seeds the existing data — so the functions, edit forms,
 * and reports keep working UNCHANGED while we cut over reads in later PRs.
 *
 * Every add is guarded with Schema::hasColumn so a partial/repeated run is a
 * no-op. None of these names collide with Snipe core columns (assets already
 * carries native order_number / supplier_id / purchase_cost / warranty_months
 * / asset_eol_date — deliberately NOT in this cluster). lease_end_date and
 * po_number are indexed because they drive the lease-end / forecast reports.
 */
return new class extends Migration
{
    /**
     * native column => [Blueprint method, args...]
     *
     * @var array<string, array{0:string, 1?:int, 2?:int}>
     */
    private array $columns = [
        'lease_contract_id'   => ['string'],
        'lease_contract_name' => ['string'],
        'lease_end_date'      => ['date'],
        'ownership_type'      => ['string'],
        'lease_rent'          => ['decimal', 12, 2],
        'buyout_cost'         => ['decimal', 12, 2],
        'decommission_date'   => ['date'],
        'po_number'           => ['string'],
        'invoice_number'      => ['string'],
        'warranty_soft_cost'  => ['decimal', 12, 2],
    ];

    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            foreach ($this->columns as $name => $spec) {
                if (Schema::hasColumn('assets', $name)) {
                    continue;
                }

                [$type] = $spec;

                if ($type === 'decimal') {
                    $table->decimal($name, $spec[1], $spec[2])->nullable();
                } elseif ($type === 'date') {
                    $table->date($name)->nullable();
                } else {
                    $table->string($name)->nullable();
                }
            }
        });

        // Indexes that the lease-end schedule + forecast reports will key on.
        Schema::table('assets', function (Blueprint $table) {
            if (Schema::hasColumn('assets', 'lease_end_date') && ! $this->hasIndex('assets', 'assets_lease_end_date_index')) {
                $table->index('lease_end_date');
            }
            if (Schema::hasColumn('assets', 'po_number') && ! $this->hasIndex('assets', 'assets_po_number_index')) {
                $table->index('po_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (Schema::hasColumn('assets', 'lease_end_date') && $this->hasIndex('assets', 'assets_lease_end_date_index')) {
                $table->dropIndex('assets_lease_end_date_index');
            }
            if (Schema::hasColumn('assets', 'po_number') && $this->hasIndex('assets', 'assets_po_number_index')) {
                $table->dropIndex('assets_po_number_index');
            }
        });

        Schema::table('assets', function (Blueprint $table) {
            foreach (array_keys($this->columns) as $name) {
                if (Schema::hasColumn('assets', $name)) {
                    $table->dropColumn($name);
                }
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->pluck('name')
            ->contains($index);
    }
};
