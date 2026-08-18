<?php

namespace App\Console\Commands;

use App\Services\Contracts\LegacyContractReconciler;
use App\Services\Contracts\LegacyContractReconcilerReport;
use Illuminate\Console\Command;

/**
 * Retire the `LIC-*` contracts that `licenses:migrate-saas-to-contracts`
 * created, wherever a TDX-sourced contract now covers the same product.
 *
 * Previews by default; pass --write to apply. Idempotent — a retired row is
 * inactive and so falls out of the next run's candidate set. Safe to
 * schedule, but the intent is a one-off sweep followed by occasional runs
 * as more legacy products get TDX contracts.
 */
class ReconcileLegacyLicenseContracts extends Command
{
    protected $signature = 'contracts:reconcile-legacy-licenses
                            {--write : Apply the changes (default is a dry-run preview)}
                            {--show-unmatched : List every legacy contract with no TDX counterpart}';

    protected $description = 'File legacy LIC-* contracts under the TDX product family that superseded them and deactivate them.';

    public function handle(LegacyContractReconciler $reconciler): int
    {
        $write = (bool) $this->option('write');

        $report = $reconciler->run($write);

        $this->info(sprintf(
            '%s scanned=%d matched=%d written=%d unmatched=%d',
            $write ? '[write]' : '[preview]',
            $report->scanned,
            $report->matched,
            $report->written,
            count($report->unmatched()),
        ));

        $this->listRetirements($report);

        if (! $write && $report->matched > 0) {
            $this->line("Re-run with --write to retire {$report->matched} legacy contract(s).");
        }

        if (count($report->unmatched()) > 0) {
            $this->warn(count($report->unmatched()).' legacy contract(s) have no TDX counterpart and were left active.');
            if ($this->option('show-unmatched')) {
                $this->table(['ID', 'Number', 'Name', 'End date'], array_map(
                    fn ($r) => [$r['id'], $r['contract_number'], $r['name'], $r['end_date'] ?? '—'],
                    $report->unmatched(),
                ));
            } else {
                $this->line('Pass --show-unmatched to list them.');
            }
        }

        return self::SUCCESS;
    }

    private function listRetirements(LegacyContractReconcilerReport $report): void
    {
        $retired = $report->retired();

        if ($retired === []) {
            return;
        }

        $this->table(['ID', 'Number', 'Name', 'End date', 'Superseded by', 'Filed under'], array_map(
            fn ($r) => [
                $r['id'],
                $r['contract_number'],
                $r['name'],
                $r['end_date'] ?? '—',
                $r['matched_contract_number'] ?? '—',
                $r['parent_name'] ?? '—',
            ],
            $retired,
        ));
    }
}
