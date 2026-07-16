<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the native lease/purchasing column set (track F2). The first PR
 * (2026_06_13_090000) promoted 10 fields; ProcurementReportsController::
 * leaseFieldColumns() resolves three more that had no native home yet — Usage,
 * Area, and Book Value — so the read cutover (F2·2) can point 100% at native
 * and the custom fieldset can eventually be retired.
 *
 * Naming: prefixed `lease_` for cluster coherence and to sidestep two hazards —
 * `usage` is a MySQL reserved word, and a bare `book_value` column would clash
 * with Snipe core's derived `book_value` API attribute (getDepreciatedValue()).
 * These native names are internal; the report keys (usage/area/book_value) are
 * unchanged, only their resolved column moves.
 *
 * Additive + guarded (Schema::hasColumn) so a partial/repeated run is a no-op.
 * A companion backfill seeds existing data; the MirrorsLeaseFields shim keeps
 * them current on every save.
 */
return new class extends Migration
{
    /**
     * native column => [Blueprint method, args...]
     *
     * @var array<string, array{0:string, 1?:int, 2?:int}>
     */
    private array $columns = [
        'lease_usage'      => ['string'],
        'lease_area'       => ['string'],
        'lease_book_value' => ['decimal', 12, 2],
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
                } else {
                    $table->string($name)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            foreach (array_keys($this->columns) as $name) {
                if (Schema::hasColumn('assets', $name)) {
                    $table->dropColumn($name);
                }
            }
        });
    }
};
