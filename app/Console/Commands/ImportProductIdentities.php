<?php

namespace App\Console\Commands;

use App\Models\ProductIdentity;
use App\Models\ProductIdentityAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the product identity catalogue from a CSV, so the vocabulary comes
 * from procurement's own product list rather than being retyped.
 *
 * Columns (header row required, case-insensitive): name, and optionally
 * publisher, procurement_name, platform, match_type, pattern. One row per
 * alias; rows that share a name build one product. A row with no pattern
 * just creates the product, ready for aliases to be added in the UI.
 *
 * Additive and idempotent: an existing product keeps its fields and gains
 * only the aliases it lacks. Dry-run unless --apply is passed.
 */
class ImportProductIdentities extends Command
{
    protected $signature = 'product-identities:import
        {file : CSV with a header row: name[,publisher,procurement_name,platform,match_type,pattern]}
        {--apply : Write the changes. Default is dry-run.}';

    protected $description = 'Create product identities and alias patterns from a CSV.';

    public function handle(): int
    {
        $rows = $this->readCsv((string) $this->argument('file'));
        if ($rows === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $products = [];
        $skipped = 0;

        foreach ($rows as $line => $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $skipped++;

                continue;
            }

            $products[mb_strtolower($name)] ??= [
                'name'             => $name,
                'publisher'        => trim($row['publisher'] ?? '') ?: null,
                'procurement_name' => trim($row['procurement_name'] ?? '') ?: null,
                'aliases'          => [],
            ];

            $pattern = trim($row['pattern'] ?? '');
            if ($pattern === '') {
                continue;
            }

            $platform = mb_strtolower(trim($row['platform'] ?? '')) ?: 'any';
            $matchType = mb_strtolower(trim($row['match_type'] ?? '')) ?: 'exact';
            if (! in_array($platform, ProductIdentityAlias::PLATFORMS, true)
                || ! in_array($matchType, ProductIdentityAlias::MATCH_TYPES, true)
                || ! ProductIdentityAlias::patternIsValid($matchType, $pattern)) {
                $this->warn("Line {$line}: skipping alias \"{$pattern}\" ({$platform}/{$matchType}) — not a valid platform, match type or pattern.");
                $skipped++;

                continue;
            }

            $products[mb_strtolower($name)]['aliases'][] = compact('platform', 'matchType', 'pattern');
        }

        $created = 0;
        $aliasesAdded = 0;

        $work = function () use ($products, $apply, &$created, &$aliasesAdded) {
            foreach ($products as $p) {
                $identity = ProductIdentity::whereRaw('LOWER(name) = ?', [mb_strtolower($p['name'])])->first();

                if (! $identity) {
                    $created++;
                    if ($apply) {
                        $identity = new ProductIdentity([
                            'name'             => $p['name'],
                            'publisher'        => $p['publisher'],
                            'procurement_name' => $p['procurement_name'],
                        ]);
                        $identity->saveOrFail();
                    }
                }

                foreach ($p['aliases'] as $a) {
                    $exists = $identity && $identity->aliases()
                        ->where('platform', $a['platform'])
                        ->where('match_type', $a['matchType'])
                        ->whereRaw('LOWER(pattern) = ?', [mb_strtolower($a['pattern'])])
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $aliasesAdded++;
                    if ($apply) {
                        $identity->aliases()->create([
                            'platform'   => $a['platform'],
                            'match_type' => $a['matchType'],
                            'pattern'    => $a['pattern'],
                        ]);
                    }
                }
            }
        };

        $apply ? DB::transaction($work) : $work();

        $this->info(($apply ? 'Applied' : 'Dry run').": {$created} product(s) to create, {$aliasesAdded} alias(es) to add, {$skipped} row(s) skipped.");
        if (! $apply) {
            $this->line('Re-run with --apply to write.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string>>|null rows keyed by line number */
    private function readCsv(string $path): ?array
    {
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return null;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, null, ',', '"', '');
        if (! $header) {
            $this->error("{$path} is empty.");

            return null;
        }
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        if (! in_array('name', $header, true)) {
            $this->error("{$path} has no \"name\" column.");

            return null;
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            $cells = array_pad($cells, count($header), '');
            $rows[$line] = array_combine($header, array_slice($cells, 0, count($header)));
        }
        fclose($handle);

        return $rows;
    }
}
