<?php

use App\Models\CustomField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the native lease / purchasing columns (added by
 * 2026_06_13_090000_add_native_lease_fields_to_assets) from the existing
 * Snipe-IT custom-field columns. Data-only: it adds NO schema, so it must not
 * change database/schema/expected-columns.json.
 *
 * Idempotent and re-runnable: each field is copied only into rows where the
 * native column is still NULL, so a second run (or a run after the dual-write
 * shim has already filled some rows) is a no-op on the already-populated rows.
 * Custom fields that don't exist in this environment are skipped. Casting
 * matches MirrorsLeaseFields exactly (dates -> Y-m-d, decimals stripped of
 * $ / commas) so the backfilled values are identical to what the shim writes.
 *
 * Uses a direct DB update (chunked over the source column) rather than loading
 * Asset models — far faster on a large fleet and it deliberately bypasses the
 * saving shim (which would be redundant here).
 */
return new class extends Migration
{
    /**
     * native column => [custom field name, cast type].
     *
     * @var array<string, array{0:string, 1:string}>
     */
    private array $map = [
        'lease_contract_id'   => ['Lease Contract ID', 'string'],
        'lease_contract_name' => ['Lease Contract Name', 'string'],
        'lease_end_date'      => ['Lease End Date', 'date'],
        'ownership_type'      => ['Ownership Type', 'string'],
        'lease_rent'          => ['Lease Rent', 'decimal'],
        'buyout_cost'         => ['Buyout Cost', 'decimal'],
        'decommission_date'   => ['Decommission Date', 'date'],
        'po_number'           => ['PO Number', 'string'],
        'invoice_number'      => ['Invoice Number', 'string'],
        'warranty_soft_cost'  => ['Warranty/Soft Cost', 'decimal'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('custom_fields')) {
            return;
        }

        $byName = CustomField::pluck('db_column', 'name');

        foreach ($this->map as $native => [$customName, $cast]) {
            // Native column must exist (structural migration ran first).
            if (! Schema::hasColumn('assets', $native)) {
                continue;
            }

            $customColumn = $byName[$customName] ?? null;
            if ($customColumn === null || ! Schema::hasColumn('assets', $customColumn)) {
                continue;
            }

            $this->backfillColumn($native, $customColumn, $cast);
        }
    }

    public function down(): void
    {
        // Data-only migration: the structural migration owns the columns and
        // their teardown. Nothing to reverse here.
    }

    private function backfillColumn(string $native, string $customColumn, string $cast): void
    {
        DB::table('assets')
            ->select('id', $customColumn)
            ->whereNull($native)
            ->whereNotNull($customColumn)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($native, $customColumn, $cast) {
                foreach ($rows as $row) {
                    $value = $this->castValue($row->{$customColumn}, $cast);
                    if ($value === null) {
                        continue;
                    }

                    DB::table('assets')
                        ->where('id', $row->id)
                        ->whereNull($native)
                        ->update([$native => $value]);
                }
            });
    }

    private function castValue(mixed $value, string $cast): mixed
    {
        return match ($cast) {
            'date'    => $this->castDate($value),
            'decimal' => $this->castDecimal($value),
            default   => $this->castString($value),
        };
    }

    private function castString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function castDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $raw = trim((string) $value);

        foreach (['Y-m-d', 'm/d/Y', 'Y/m/d', 'd/m/Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $raw);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function castDecimal(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $cleaned = trim(str_replace(['$', ','], '', (string) $value));

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }
};
