<?php

namespace App\Console\Commands;

use App\Services\Leasing\LeaseNameSyncService;
use Illuminate\Console\Command;

/**
 * Mirror the lease display name from the contracts register onto leased assets.
 * Previews by default; pass --write to apply. Idempotent — a second run reports
 * nothing to do — so it's scheduled nightly to keep a renamed contract from
 * leaving the fleet behind again.
 */
class SyncLeaseContractNames extends Command
{
    protected $signature = 'snipeit:sync-lease-names
                            {--write : Apply the changes (default is a dry-run preview)}';

    protected $description = 'Sync asset lease contract names from the contracts register.';

    public function handle(LeaseNameSyncService $service): int
    {
        $write = (bool) $this->option('write');
        $report = $service->run($write);

        if ($report['changes'] !== []) {
            $this->table(
                ['Contract', 'From', 'To', 'Assets'],
                array_map(fn ($c) => [$c['contract'], $c['from'], $c['to'], $c['assets']], $report['changes']),
            );
        }

        $this->info(sprintf(
            '%s scanned=%d matched=%d %s=%d',
            $write ? '[write]' : '[preview]',
            $report['scanned'],
            $report['matched'],
            $write ? 'updated' : 'would-update',
            $report['written'],
        ));

        if ($report['unmatched'] !== []) {
            $total = array_sum($report['unmatched']);
            $this->warn(sprintf(
                '%d asset(s) carry a lease contract id with no register row — names left untouched:',
                $total,
            ));
            foreach ($report['unmatched'] as $contractId => $count) {
                $this->line(sprintf('  %-22s %d asset(s)', $contractId, $count));
            }
        }

        if (! $write && $report['written'] > 0) {
            $this->line("Re-run with --write to update {$report['written']} asset(s).");
        }

        return self::SUCCESS;
    }
}
