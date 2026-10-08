<?php

namespace App\Console\Commands;

use App\Services\ProductIdentity\ProductResolver;
use Illuminate\Console\Command;

/**
 * Reads an export of observed application usage and reports what the
 * product identity catalogue does not cover: the high-usage applications
 * nothing resolves, and the products that resolve but have no license in
 * the fiscal year. Read-only.
 *
 * Columns (header row required, case-insensitive): name (or application,
 * app), and optionally platform, usage (or hours, usage_hours, launches)
 * and devices.
 */
class ReportUnmappedApplications extends Command
{
    protected $signature = 'product-identities:unmapped
        {file : CSV of observed applications: name[,platform,usage,devices]}
        {--fy= : Fiscal year to check license links against, e.g. FY2026-27}
        {--min-usage=0 : Leave out unmapped applications used less than this}
        {--limit=50 : Show at most this many unmapped applications}';

    protected $description = 'Report observed applications that no product identity claims.';

    private const COLUMN_ALIASES = [
        'name'    => ['name', 'application', 'app', 'application_name', 'app_name'],
        'platform' => ['platform', 'os'],
        'usage'   => ['usage', 'hours', 'usage_hours', 'launches', 'launch_count'],
        'devices' => ['devices', 'device_count', 'unique_devices'],
    ];

    public function handle(ProductResolver $resolver): int
    {
        $observations = $this->readCsv((string) $this->argument('file'));
        if ($observations === null) {
            return self::FAILURE;
        }

        $report = $resolver->partition($observations, $this->option('fy'), (float) $this->option('min-usage'));
        $limit = max(1, (int) $this->option('limit'));

        $this->info(sprintf(
            '%d product(s) resolved, %d unmapped application(s) at or above the usage floor, %d below it.',
            count($report['mapped']),
            count($report['unmapped']),
            $report['unmapped_below_threshold'],
        ));

        if ($report['unmapped'] !== []) {
            $this->line('');
            $this->line('Unmapped, highest usage first:');
            $this->table(
                ['Application', 'Platform', 'Usage', 'Devices'],
                array_map(fn ($a) => [$a['name'], $a['platform'] ?? '', $a['usage'], $a['devices']], array_slice($report['unmapped'], 0, $limit)),
            );
        }

        $unlicensed = array_values(array_filter($report['mapped'], fn ($p) => ! $p['licensed']));
        if ($unlicensed !== []) {
            $this->line('');
            $this->line('Resolved but not linked to a license'.($report['fiscal_year'] ? " in {$report['fiscal_year']}" : '').':');
            $this->table(
                ['Product', 'Usage', 'Applications'],
                array_map(fn ($p) => [$p['name'], $p['usage'], count($p['applications'])], $unlicensed),
            );
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string>>|null */
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
        $index = [];
        foreach (self::COLUMN_ALIASES as $field => $names) {
            foreach ($names as $candidate) {
                $pos = array_search($candidate, $header, true);
                if ($pos !== false) {
                    $index[$field] = $pos;
                    break;
                }
            }
        }
        if (! isset($index['name'])) {
            $this->error("{$path} has no application name column (name, application or app).");

            return null;
        }

        $rows = [];
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $row = [];
            foreach ($index as $field => $pos) {
                $row[$field] = $cells[$pos] ?? null;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
