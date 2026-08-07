<?php

namespace App\Console\Commands;

use App\Services\Leasing\LeaseOwnershipReconciler;
use Illuminate\Console\Command;

/**
 * Correct `ownership_type` on leased assets whose status already says they came
 * off the lease. Previews by default; pass --write to apply. Idempotent, and
 * only ever moves an asset to Purchased, so it is safe to schedule.
 */
class ReconcileLeaseOwnership extends Command
{
    protected $signature = 'snipeit:reconcile-lease-ownership
                            {--write : Apply the changes (default is a dry-run preview)}';

    protected $description = 'Set ownership_type to Purchased where a buyout status says the asset left its lease.';

    public function handle(LeaseOwnershipReconciler $reconciler): int
    {
        $write = (bool) $this->option('write');
        $report = $reconciler->run($write);

        foreach ($report['by_status'] as $status => $count) {
            $this->line(sprintf('  %-20s %d asset(s)', $status, $count));
        }

        $this->info(sprintf(
            '%s candidates=%d %s=%d',
            $write ? '[write]' : '[preview]',
            $report['candidates'],
            $write ? 'updated' : 'would-update',
            $write ? $report['written'] : $report['candidates'],
        ));

        if (! $write && $report['candidates'] > 0) {
            $this->line("Re-run with --write to correct {$report['candidates']} asset(s).");
        }

        return self::SUCCESS;
    }
}
