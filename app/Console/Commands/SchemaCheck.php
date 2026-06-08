<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Detects "recorded-but-missing" schema drift: a migration is listed in the
 * migrations table (so `migrate --force` will never re-run it) yet its column
 * never actually landed in the live database. This is invisible to Laravel's
 * own migrate status and to the route smoke-crawler, and it took down every
 * consumable write on prod once (the on_maintenance_contract column — see
 * 2026_06_08_140000_readd_on_maintenance_contract_to_consumables).
 *
 * It works off a committed snapshot of the expected (table -> columns) shape,
 * generated from a freshly-migrated database. CI keeps the snapshot honest
 * (regenerates it and fails on any uncommitted diff), and container startup
 * runs the check after migrate to surface drift loudly in the boot log.
 *
 *   php artisan schema:check          # compare live DB to the snapshot
 *   php artisan schema:check --dump   # (re)generate the snapshot from this DB
 *
 * Custom-field columns (Snipe-IT names them `_snipeit_*` on the assets table)
 * are environment-specific data, not migration output, so they are excluded
 * from the snapshot; extra columns present live but not in the snapshot are
 * ignored — only snapshot columns missing live count as drift.
 */
class SchemaCheck extends Command
{
    protected $signature = 'schema:check {--dump : Regenerate the expected-columns snapshot from the connected database}';

    protected $description = 'Detect recorded-but-missing schema drift by diffing the live DB against a committed column snapshot';

    private const SNAPSHOT = 'database/schema/expected-columns.json';

    /** Custom-field / volatile column prefixes that legitimately vary per environment. */
    private const IGNORE_COLUMN_PREFIXES = ['_snipeit_'];

    public function handle(): int
    {
        return $this->option('dump') ? $this->dump() : $this->check();
    }

    private function dump(): int
    {
        $map = [];
        foreach ($this->baseTables() as $table) {
            $columns = collect(Schema::getColumnListing($table))
                ->reject(fn ($c) => $this->ignored($c))
                ->sort()
                ->values()
                ->all();
            $map[$table] = $columns;
        }
        ksort($map);

        $path = base_path(self::SNAPSHOT);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info('Wrote '.self::SNAPSHOT.' ('.count($map).' tables, '.collect($map)->flatten()->count().' columns).');

        return self::SUCCESS;
    }

    private function check(): int
    {
        $path = base_path(self::SNAPSHOT);
        if (! is_file($path)) {
            $this->error('No snapshot at '.self::SNAPSHOT.'. Generate it with: php artisan schema:check --dump');

            return self::FAILURE;
        }

        $expected = json_decode(file_get_contents($path), true);
        if (! is_array($expected)) {
            $this->error('Snapshot at '.self::SNAPSHOT.' is not valid JSON.');

            return self::FAILURE;
        }

        $missingTables = [];
        $missingColumns = [];

        foreach ($expected as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missingTables[] = $table;

                continue;
            }
            $live = Schema::getColumnListing($table);
            foreach ($columns as $column) {
                if (! in_array($column, $live, true)) {
                    $missingColumns[] = $table.'.'.$column;
                }
            }
        }

        if (! $missingTables && ! $missingColumns) {
            $this->info('schema:check OK — live database matches the expected snapshot ('.count($expected).' tables).');

            return self::SUCCESS;
        }

        $this->error('SCHEMA DRIFT DETECTED — the live database is missing schema that migrations claim to have applied.');
        $this->newLine();
        foreach ($missingTables as $t) {
            $this->line('  MISSING TABLE:  '.$t);
        }
        foreach ($missingColumns as $c) {
            $this->line('  MISSING COLUMN: '.$c);
        }
        $this->newLine();
        $this->warn('A migration is recorded as run but its change never landed. `migrate --force` will NOT heal it — add a guarded (Schema::hasColumn) re-add migration.');

        return self::FAILURE;
    }

    /** @return string[] base table names (excludes views) */
    private function baseTables(): array
    {
        return collect(Schema::getTables())
            ->filter(fn ($t) => ($t['type'] ?? 'table') === 'table')
            ->map(fn ($t) => $t['name'])
            ->sort()
            ->values()
            ->all();
    }

    private function ignored(string $column): bool
    {
        foreach (self::IGNORE_COLUMN_PREFIXES as $prefix) {
            if (str_starts_with($column, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
