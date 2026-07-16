<?php

namespace App\Models\Traits;

use App\Models\CustomField;
use Illuminate\Support\Facades\Schema;

/**
 * Dual-write shim for the lease / purchasing cluster (PR 1 of the native-field
 * migration). The data still lives in Snipe-IT custom fields (the `_snipeit_*`
 * generated columns the Azure Functions write to). This trait mirrors each of
 * those values into the matching NATIVE typed column on every Asset save, so
 * the functions, edit forms, and API keep writing `_snipeit_*` UNCHANGED while
 * the native columns fill in behind them — letting later PRs cut reads over to
 * the native columns with no flag-day.
 *
 * Resolution is by custom-field NAME -> db_column (mirrors
 * ProcurementReportsController::leaseFieldColumns) because the `_snipeit_*`
 * suffix differs per environment; never hardcode the column name. The map is
 * resolved once per request and cached statically.
 *
 * The mirror only fires when the source custom column isDirty on this save, so
 * a later manual edit straight to a native column is never clobbered by a stale
 * custom value. The whole hook is a guarded no-op when the custom fields don't
 * exist (fresh DB) or the native columns aren't there yet.
 */
trait MirrorsLeaseFields
{
    /**
     * native column => [custom field name, cast type].
     * Cast types: 'string', 'date', 'decimal'.
     *
     * @var array<string, array{0:string, 1:string}>
     */
    protected static array $leaseFieldMap = [
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
        'lease_usage'         => ['Usage', 'string'],
        'lease_area'          => ['Area', 'string'],
        'lease_book_value'    => ['Book Value', 'decimal'],
    ];

    /**
     * Cached native column => custom db_column resolution, per request.
     * null entries mean "resolved, but no such custom field exists".
     *
     * @var array<string, string|null>|null
     */
    protected static ?array $leaseCustomColumns = null;

    public static function bootMirrorsLeaseFields(): void
    {
        static::saving(function ($asset): void {
            $asset->mirrorLeaseFieldsToNative();
        });
    }

    /**
     * native column => [custom field name, cast type], for callers that need
     * to reproduce the mirror's field set and casting (e.g. the
     * `lease:verify-native` parity command). Reusing this map — rather than
     * duplicating it — keeps the verifier's normalization identical to what
     * the shim writes, so honest data never reads as drift.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function leaseFieldMap(): array
    {
        return static::$leaseFieldMap;
    }

    /**
     * Native `assets` column for a lease custom field's display name, or null
     * if the name isn't part of the lease cluster. Lets callers that resolve a
     * field by name (config-driven ones especially) reach the native column
     * without a per-environment `_snipeit_*` db_column lookup.
     */
    public static function nativeColumnForCustomName(string $name): ?string
    {
        foreach (static::$leaseFieldMap as $native => [$customName]) {
            if ($customName === $name) {
                return $native;
            }
        }

        return null;
    }

    /**
     * Resolve native column => custom field db_column, once per request.
     * Returns [] (and is a no-op upstream) if the custom_fields table is
     * absent — keeps a fresh DB / early-boot save from blowing up.
     *
     * @return array<string, string|null>
     */
    public static function leaseCustomColumnMap(): array
    {
        if (static::$leaseCustomColumns !== null) {
            return static::$leaseCustomColumns;
        }

        if (! Schema::hasTable('custom_fields')) {
            return static::$leaseCustomColumns = [];
        }

        $names = collect(static::$leaseFieldMap)->map(fn ($spec) => $spec[0])->values()->all();

        // One query for the whole cluster: name => db_column.
        $byName = CustomField::whereIn('name', $names)
            ->pluck('db_column', 'name');

        $map = [];
        foreach (static::$leaseFieldMap as $native => [$customName]) {
            $map[$native] = $byName[$customName] ?? null;
        }

        return static::$leaseCustomColumns = $map;
    }

    /**
     * Copy each dirty custom-field value into its native column, casting per
     * type. No-op when the native column doesn't exist yet, when the custom
     * field is absent, or when the source column isn't dirty on this save.
     */
    protected function mirrorLeaseFieldsToNative(): void
    {
        $map = static::leaseCustomColumnMap();
        if ($map === []) {
            return;
        }

        foreach (static::$leaseFieldMap as $native => [, $cast]) {
            $customColumn = $map[$native] ?? null;
            if ($customColumn === null) {
                continue;
            }

            // Native column not migrated in yet — leave it alone.
            if (! Schema::hasColumn('assets', $native)) {
                continue;
            }

            // Only mirror when the source actually changed on THIS save, so a
            // later manual native edit isn't overwritten by a stale value.
            if (! $this->isDirty($customColumn)) {
                continue;
            }

            $this->setAttribute($native, static::castLeaseValue($this->getAttribute($customColumn), $cast));
        }
    }

    /**
     * Cast a raw custom-field value to the native column's type.
     * Returns null for blank / unparseable input. Public so the parity
     * verifier casts source values exactly as the mirror does.
     */
    public static function castLeaseValue(mixed $value, string $cast): mixed
    {
        return match ($cast) {
            'date'    => static::castLeaseDate($value),
            'decimal' => static::castLeaseDecimal($value),
            default   => static::castLeaseString($value),
        };
    }

    protected static function castLeaseString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Parse a date string to Y-m-d, accepting the same formats as
     * ProcurementReportsController::fiscalYearFromEndDate. Null on blank or
     * unparseable input.
     */
    protected static function castLeaseDate(mixed $value): ?string
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

    /**
     * Strip currency symbols / thousands separators and cast to float.
     * Null on blank or non-numeric input.
     */
    protected static function castLeaseDecimal(mixed $value): ?float
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
}
