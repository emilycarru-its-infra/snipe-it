<?php

namespace App\Console\Commands;

use App\Services\Leasing\LessorBackfillService;
use Illuminate\Console\Command;

/**
 * Populate the native asset `lessor_id` for leased devices from the Lease
 * Contract ID prefix. Previews by default (lists what it would set and any
 * assets it can't resolve); pass --write to actually apply. Idempotent and
 * non-destructive — only ever fills a null lessor_id, never overwrites — so
 * it's scheduled nightly to keep newly-ingested leases populated.
 */
class BackfillLessors extends Command
{
    protected $signature = 'snipeit:backfill-lessors
                            {--write : Apply the changes (default is a dry-run preview)}
                            {--show-unresolved : List every asset whose lessor could not be derived}';

    protected $description = 'Backfill asset lessor_id for leased devices from the Lease Contract ID prefix.';

    public function handle(LessorBackfillService $service): int
    {
        $write = (bool) $this->option('write');

        $report = $service->run($write);

        $this->info(sprintf(
            '%s scanned=%d resolvable=%d written=%d unresolved=%d',
            $write ? '[write]' : '[preview]',
            $report->scanned,
            $report->resolved,
            $report->written,
            count($report->unresolved),
        ));

        if (! $write && $report->resolved > 0) {
            $this->line("Re-run with --write to set lessor_id on {$report->resolved} asset(s).");
        }

        if (count($report->unresolved) > 0) {
            $this->warn(count($report->unresolved).' leased asset(s) need a manual lessor (unrecognised or missing Lease Contract ID).');
            if ($this->option('show-unresolved')) {
                $this->table(['Asset ID', 'Asset Tag', 'Lease Contract ID'], array_map(
                    fn ($r) => [$r['id'], $r['asset_tag'], $r['contract_id'] === '' ? '—' : $r['contract_id']],
                    $report->unresolved,
                ));
            } else {
                $this->line('Pass --show-unresolved to list them.');
            }
        }

        return self::SUCCESS;
    }
}
