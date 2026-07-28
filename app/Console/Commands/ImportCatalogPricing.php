<?php

namespace App\Console\Commands;

use App\Services\CatalogPriceListImport;
use Illuminate\Console\Command;

/**
 * Loads a reseller price list into the purchasing catalog from the command
 * line — the path for local work, dev boxes and scheduled jobs.
 *
 * The merge itself lives in CatalogPriceListImport, shared with the API
 * endpoint and Snipe's importer UI, so a list loaded here lands exactly the
 * same way as one loaded by clicking.
 *
 * This cannot reach a deployed environment: the App Service containers ship
 * no shell. Use the API endpoint or the importer UI for dev and production.
 */
class ImportCatalogPricing extends Command
{
    protected $signature = 'catalog:import
        {file : Path to the price list (.xlsx or .csv)}
        {--supplier= : Supplier name the list came from, e.g. CDW (created if missing)}
        {--source= : Label for this price list, e.g. "Apple EDC July 2026" (defaults to the filename)}
        {--quoted-at= : Date the list was issued (Y-m-d), defaults to today}
        {--expires-at= : Date the pricing lapses (Y-m-d)}
        {--deactivate-missing : Mark catalog rows from this source that are absent from the file as inactive}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Import a reseller price list (CDW and friends) into the purchasing catalog.';

    public function handle(CatalogPriceListImport $importer): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $result = $importer->importFile($path, [
            'supplier' => $this->option('supplier'),
            'source' => $this->option('source') ?: pathinfo($path, PATHINFO_FILENAME),
            'quoted_at' => $this->option('quoted-at'),
            'expires_at' => $this->option('expires-at'),
            'deactivate_missing' => (bool) $this->option('deactivate-missing'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        if ($result['rows'] === 0) {
            $this->error('No data rows found — is the header row present?');

            return self::FAILURE;
        }

        $this->line(sprintf('Read %d rows from %s', $result['rows'], basename($path)));

        $this->info(sprintf(
            '%s%d created, %d updated, %d skipped%s.',
            $this->option('dry-run') ? '[dry run] ' : '',
            $result['created'],
            $result['updated'],
            $result['skipped'],
            $result['deactivated'] ? ', '.$result['deactivated'].' deactivated' : ''
        ));

        return self::SUCCESS;
    }
}
