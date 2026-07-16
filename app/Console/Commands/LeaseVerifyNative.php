<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parity gate for the native-lease read cutover (track F2, phase F2·2).
 *
 * The MirrorsLeaseFields shim dual-writes each lease custom field
 * (`_snipeit_*`) into its native `assets` column, and a backfill seeded the
 * existing rows. Before any reader is repointed from the custom column to the
 * native column, this command proves the two agree for every asset: for each
 * field it recomputes what the shim WOULD write from the current custom value
 * (reusing the trait's own casting, so honest data never reads as drift) and
 * compares it to what actually sits in the native column.
 *
 * Three outcomes per field/row:
 *   - OK          native matches cast(custom)
 *   - MISMATCH    native differs from cast(custom) — real drift; the backfill
 *                 didn't cover this row, or something wrote one side only.
 *                 The gate FAILS on any mismatch.
 *   - UNPARSEABLE custom holds a non-empty value the cast can't parse (a bad
 *                 date / non-numeric money), so both sides are null. Not drift
 *                 — a data-quality finding to clean at the source. Reported,
 *                 does not fail the gate.
 *
 *   php artisan lease:verify-native            # human table, exit 1 on mismatch
 *   php artisan lease:verify-native --json      # machine-readable summary
 *   php artisan lease:verify-native --samples=50
 */
class LeaseVerifyNative extends Command
{
    protected $signature = 'lease:verify-native
        {--json : Emit a machine-readable JSON summary instead of a table}
        {--samples=20 : Max example asset ids to list per problem field}';

    protected $description = 'Verify every asset\'s native lease columns match cast(custom field) — gate for the read cutover';

    public function handle(): int
    {
        $samples = max(0, (int) $this->option('samples'));
        $map = Asset::leaseFieldMap();                 // native => [custom name, cast]
        $customColumns = Asset::leaseCustomColumnMap(); // native => custom db_column|null

        if ($customColumns === []) {
            $this->error('custom_fields table absent — cannot resolve lease columns. Is this a seeded/empty DB?');

            return self::FAILURE;
        }

        // Resolve which fields are actually comparable in this environment:
        // both the native column and the custom field must exist.
        $fields = [];   // native => ['custom' => db_column, 'cast' => type]
        $skipped = [];  // native => reason
        foreach ($map as $native => [$customName, $cast]) {
            $customCol = $customColumns[$native] ?? null;
            if (! Schema::hasColumn('assets', $native)) {
                $skipped[$native] = 'native column missing';

                continue;
            }
            if ($customCol === null || ! Schema::hasColumn('assets', $customCol)) {
                $skipped[$native] = "custom field \"$customName\" absent";

                continue;
            }
            $fields[$native] = ['custom' => $customCol, 'cast' => $cast];
        }

        if ($fields === []) {
            $this->error('No lease fields are comparable in this environment (see skipped list).');
            foreach ($skipped as $native => $reason) {
                $this->line("  - $native: $reason");
            }

            return self::FAILURE;
        }

        // Per-field tallies + a bounded set of example asset ids.
        $stats = [];
        foreach ($fields as $native => $_) {
            $stats[$native] = ['ok' => 0, 'mismatch' => 0, 'unparseable' => 0,
                'mismatch_ids' => [], 'unparseable_ids' => []];
        }

        $select = array_merge(['id'], array_keys($fields), array_map(fn ($f) => $f['custom'], $fields));
        $scanned = 0;

        DB::table('assets')->select(array_unique($select))->orderBy('id')
            ->chunk(2000, function ($rows) use ($fields, &$stats, &$scanned, $samples) {
                foreach ($rows as $row) {
                    $scanned++;
                    foreach ($fields as $native => $spec) {
                        $raw = $row->{$spec['custom']};
                        $expected = Asset::castLeaseValue($raw, $spec['cast']);
                        $actual = $row->{$native};

                        if (! $this->sameValue($actual, $expected, $spec['cast'])) {
                            $stats[$native]['mismatch']++;
                            if (count($stats[$native]['mismatch_ids']) < $samples) {
                                $stats[$native]['mismatch_ids'][] = $row->id;
                            }

                            continue;
                        }

                        // Matches — but flag non-empty source that casts to null.
                        if ($expected === null && $raw !== null && trim((string) $raw) !== '') {
                            $stats[$native]['unparseable']++;
                            if (count($stats[$native]['unparseable_ids']) < $samples) {
                                $stats[$native]['unparseable_ids'][] = $row->id;
                            }

                            continue;
                        }

                        $stats[$native]['ok']++;
                    }
                }
            });

        $totalMismatch = array_sum(array_column($stats, 'mismatch'));
        $totalUnparseable = array_sum(array_column($stats, 'unparseable'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'scanned' => $scanned,
                'total_mismatch' => $totalMismatch,
                'total_unparseable' => $totalUnparseable,
                'passed' => $totalMismatch === 0,
                'fields' => $stats,
                'skipped' => $skipped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $totalMismatch === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Scanned $scanned assets across ".count($fields).' lease fields.');
        $this->newLine();

        $this->table(
            ['Native column', 'OK', 'Mismatch', 'Unparseable'],
            collect($fields)->keys()->map(fn ($native) => [
                $native,
                $stats[$native]['ok'],
                $stats[$native]['mismatch'] ?: '-',
                $stats[$native]['unparseable'] ?: '-',
            ])->all()
        );

        if ($skipped !== []) {
            $this->newLine();
            $this->warn('Skipped (not comparable in this environment):');
            foreach ($skipped as $native => $reason) {
                $this->line("  - $native: $reason");
            }
        }

        if ($totalMismatch > 0) {
            $this->newLine();
            $this->error("DRIFT: $totalMismatch native/custom mismatches — read cutover is NOT safe.");
            foreach ($stats as $native => $s) {
                if ($s['mismatch'] > 0) {
                    $this->line("  $native: ".$s['mismatch'].' (e.g. asset ids '.implode(', ', $s['mismatch_ids']).')');
                }
            }
        }

        if ($totalUnparseable > 0) {
            $this->newLine();
            $this->warn("$totalUnparseable unparseable source values (data quality, not drift — both sides null):");
            foreach ($stats as $native => $s) {
                if ($s['unparseable'] > 0) {
                    $this->line("  $native: ".$s['unparseable'].' (e.g. asset ids '.implode(', ', $s['unparseable_ids']).')');
                }
            }
        }

        if ($totalMismatch === 0) {
            $this->newLine();
            $this->info('PASS: every native lease column matches cast(custom). Read cutover is safe.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * Does the native column value equal what the shim would have written?
     * Normalises both sides per cast so 1234 vs "1234.00" and null vs "" don't
     * read as false drift.
     */
    private function sameValue(mixed $actualRaw, mixed $expected, string $cast): bool
    {
        return $this->canonicalActual($actualRaw, $cast) === $this->canonicalExpected($expected, $cast);
    }

    private function canonicalExpected(mixed $expected, string $cast): ?string
    {
        if ($expected === null) {
            return null;
        }
        if ($cast === 'decimal') {
            return number_format((float) $expected, 2, '.', '');
        }

        return (string) $expected;
    }

    private function canonicalActual(mixed $actualRaw, string $cast): ?string
    {
        if ($actualRaw === null || $actualRaw === '') {
            return null;
        }
        if ($cast === 'decimal') {
            return number_format((float) $actualRaw, 2, '.', '');
        }
        if ($cast === 'date') {
            // MySQL DATE comes back as Y-m-d already; guard a datetime just in case.
            return substr((string) $actualRaw, 0, 10);
        }

        return (string) $actualRaw;
    }
}
