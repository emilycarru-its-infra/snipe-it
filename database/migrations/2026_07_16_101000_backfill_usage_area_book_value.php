<?php

use App\Models\CustomField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the three native columns added by
 * 2026_07_16_100000_add_usage_area_book_value_to_assets from their existing
 * Snipe-IT custom fields. Data-only: adds NO schema, so it must not change
 * database/schema/expected-columns.json.
 *
 * Idempotent (copies only where the native column is still NULL), casting
 * identical to MirrorsLeaseFields so backfilled values match what the shim
 * writes. Chunked direct DB update, deliberately bypassing the saving shim.
 * Mirrors 2026_06_13_091000 for the original ten.
 */
return new class extends Migration
{
    /**
     * native column => [custom field name, cast type].
     *
     * @var array<string, array{0:string, 1:string}>
     */
    private array $map = [
        'lease_usage'      => ['Usage', 'string'],
        'lease_area'       => ['Area', 'string'],
        'lease_book_value' => ['Book Value', 'decimal'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('custom_fields')) {
            return;
        }

        $byName = CustomField::pluck('db_column', 'name');

        foreach ($this->map as $native => [$customName, $cast]) {
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
        // Data-only migration: the structural migration owns the columns.
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
