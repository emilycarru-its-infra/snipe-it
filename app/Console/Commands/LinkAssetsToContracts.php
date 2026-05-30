<?php

namespace App\Console\Commands;

use App\Services\UserAgreements\AssetContractLinker;
use Illuminate\Console\Command;

/**
 * One-shot migration runner. Walks assets that carry lease info in
 * the legacy custom fields and ties them to real Contract entities
 * via the contract_asset bridge. After this runs, the Reconciler
 * picks up lease state straight from Contract.end_date without
 * caring about the custom fields.
 *
 * Idempotent — re-runs only do work for newly-added assets or
 * contracts. --dry-run reports the plan without writing.
 */
class LinkAssetsToContracts extends Command
{
    protected $signature = 'snipeit:link-assets-to-contracts
                            {--asset= : Limit to a single asset_id (skip the full sweep)}
                            {--dry-run : Print the plan without writing}';

    protected $description = 'Backfill contract_asset bridge rows from legacy lease-info custom fields.';

    public function handle(AssetContractLinker $linker): int
    {
        $assetId = $this->option('asset') ? (int) $this->option('asset') : null;
        $dryRun  = (bool) $this->option('dry-run');

        $report = $linker->run($dryRun, $assetId);

        $this->info(sprintf(
            '%s assets_scanned=%d assets_skipped=%d bridges_planned=%d bridges_created=%d bridges_already=%d contracts_planned=%d contracts_created=%d',
            $dryRun ? '[dry-run]' : '[run]',
            $report->assetsScanned,
            $report->assetsSkipped,
            $report->bridgesPlanned,
            $report->bridgesCreated,
            $report->bridgesAlreadyPresent,
            $report->contractsPlanned,
            $report->contractsCreated,
        ));

        return self::SUCCESS;
    }
}
