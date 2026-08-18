<?php

namespace App\Console\Commands;

use App\Mail\ContractRenewalAlertMail;
use App\Models\Contract;
use App\Models\EmailTemplate;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Scans contracts and sends renewal alerts at three windows: 30 days
 * out, 14 days out, and a digest of recently-expired (within the last
 * 7 days). Each contract has three nullable timestamp columns that
 * track which alerts have already fired, so re-runs are idempotent.
 *
 * Recipient resolution per contract:
 *   1. If `admin_user_id` is set and that user has an email → email them.
 *   2. Otherwise fall back to Setting::alert_email (global list).
 *
 * Schedule daily in routes/console.php. Safe to run by hand:
 *   php artisan snipeit:contract-renewals --dry-run
 */
class SendContractRenewalAlerts extends Command
{
    protected $signature = 'snipeit:contract-renewals
                            {--dry-run : Show what would be sent without emailing or marking}
                            {--force   : Re-send even if the alert timestamp is already set}';

    protected $description = 'Send 30-day, 14-day, and expired contract renewal alerts.';

    public function handle(): int
    {
        $settings = Setting::getSettings();

        if (! $settings || $settings->alerts_enabled != 1) {
            $this->info('Alerts disabled in settings — nothing to do.');
            return self::SUCCESS;
        }

        $today    = Carbon::today();
        $dryRun   = (bool) $this->option('dry-run');
        $force    = (bool) $this->option('force');
        // Per-contract admin_user wins; otherwise fall back to the per-email
        // recipient override (Settings → Emails) ?? the global alert_email list.
        $fallback = EmailTemplate::recipientsFor('report.contract_renewal', $settings->alert_email);

        $sent = ['30d' => 0, '14d' => 0, 'expired' => 0];

        foreach (['30d', '14d', 'expired'] as $window) {
            $contracts = $this->contractsForWindow($window, $today, $force);

            if ($contracts->isEmpty()) {
                $this->line("[$window] no contracts to alert on");
                continue;
            }

            // Group by recipient address(es) so each owner gets ONE email
            // covering all of THEIR contracts in this window.
            $grouped = $this->groupByRecipients($contracts, $fallback);

            foreach ($grouped as $recipientsKey => $bag) {
                $recipients = $bag['recipients'];
                $rows       = $bag['contracts'];

                if (empty($recipients)) {
                    $this->warn("[$window] {$rows->count()} contracts have no recipient (admin_user empty + no Setting::alert_email) — skipped");
                    continue;
                }

                if ($dryRun) {
                    $this->info(sprintf(
                        '[%s] DRY-RUN would email %s with %d contract(s)',
                        $window,
                        implode(',', $recipients),
                        $rows->count(),
                    ));
                    continue;
                }

                try {
                    Mail::to($recipients)->send(new ContractRenewalAlertMail($rows, $window));
                    $this->markAlerted($rows, $window);
                    $sent[$window] += $rows->count();
                    $this->info(sprintf(
                        '[%s] sent to %s — %d contracts',
                        $window,
                        implode(',', $recipients),
                        $rows->count(),
                    ));
                } catch (\Throwable $e) {
                    Log::error("Contract renewal alert failed for window=$window: ".$e->getMessage(), [
                        'recipients' => $recipients,
                        'contracts'  => $rows->pluck('id')->all(),
                    ]);
                    $this->error("[$window] mail send failed: ".$e->getMessage());
                }
            }
        }

        $this->info(sprintf('Done. Sent: 30d=%d, 14d=%d, expired=%d',
            $sent['30d'], $sent['14d'], $sent['expired']));

        return self::SUCCESS;
    }

    /**
     * Pulls contracts matching the given window. Tolerance is ±2 days
     * so a single daily run won't miss a date because cron fired a few
     * hours late.
     */
    private function contractsForWindow(string $window, Carbon $today, bool $force): Collection
    {
        $query = Contract::query()
            ->with(['owner', 'supplier'])
            ->active()
            ->realOnly()
            ->whereNotNull('end_date');

        $rows = match ($window) {
            '30d' => $query
                ->whereBetween('end_date', [$today->copy()->addDays(28), $today->copy()->addDays(32)])
                ->when(! $force, fn ($q) => $q->whereNull('last_renewal_alert_30d_at'))
                ->get(),

            '14d' => $query
                ->whereBetween('end_date', [$today->copy()->addDays(12), $today->copy()->addDays(16)])
                ->when(! $force, fn ($q) => $q->whereNull('last_renewal_alert_14d_at'))
                ->get(),

            'expired' => $query
                ->whereBetween('end_date', [$today->copy()->subDays(7), $today->copy()->subDay()])
                ->when(! $force, fn ($q) => $q->whereNull('last_renewal_alert_expired_at'))
                ->get(),

            default => new Collection,
        };

        return $this->suppressNonRenewals($rows, $window);
    }

    /**
     * Drops rows that are in the window but are not something a human
     * needs to act on. Two cases, both of which have shipped real false
     * alerts:
     *
     *  1. Zero-length terms. TDX's renewal automation mints the next
     *     fiscal year's contract with EndDate copied from StartDate, so
     *     a year-long renewal looks like it expires the day it starts.
     *     The ingest mirrors TDX faithfully and the row lands in the
     *     window on its own start date.
     *
     *  2. Already-renewed terms. When the successor for the next fiscal
     *     year is already on file, the outgoing term expiring is not
     *     news. Suppressing it is what makes one product produce one
     *     alert instead of one per fiscal year on record.
     *
     * Neither case is stamped as alerted, so if the successor is later
     * deleted or its dates corrected, the predecessor alerts again on the
     * next run.
     */
    private function suppressNonRenewals(Collection $contracts, string $window): Collection
    {
        if ($contracts->isEmpty()) {
            return $contracts;
        }

        $kept = $contracts->reject(function (Contract $contract) use ($window) {
            if ($this->isZeroLengthTerm($contract)) {
                $this->line(sprintf(
                    '[%s] skipping %s (%s) — start and end dates are the same, term is unset upstream',
                    $window,
                    $contract->contract_number,
                    $contract->name,
                ));

                return true;
            }

            if ($successor = $this->successorFor($contract)) {
                $this->line(sprintf(
                    '[%s] skipping %s (%s) — already renewed by %s ending %s',
                    $window,
                    $contract->contract_number,
                    $contract->name,
                    $successor->contract_number,
                    $successor->end_date?->toDateString(),
                ));

                return true;
            }

            return false;
        });

        return $kept->values();
    }

    /**
     * A term whose start and end land on the same day carries no duration
     * and is always an upstream data defect, never a real one-day contract.
     */
    private function isZeroLengthTerm(Contract $contract): bool
    {
        return $contract->start_date
            && $contract->end_date
            && $contract->start_date->isSameDay($contract->end_date);
    }

    /**
     * The live contract that covers the period after this one, or null.
     *
     * Renewal families are identified by the synthesized parent first —
     * that row is keyed on (provider, product) by the TDX ingest and is
     * the only identifier that survives the theme drifting between
     * cycles ("Device Software" -> "ISS"). Rows with no parent fall back
     * to supplier + product, then supplier + name, so legacy contracts
     * that never got filed under a parent still group. A contract with
     * no usable family key has no successor and always alerts.
     */
    private function successorFor(Contract $contract): ?Contract
    {
        $query = Contract::query()
            ->active()
            ->realOnly()
            ->whereKeyNot($contract->getKey())
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>', $contract->end_date)
            // A successor with a broken term proves nothing about coverage.
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereColumn('start_date', '<>', 'end_date'))
            ->orderBy('end_date');

        if ($contract->parent_contract_id) {
            return $query->where('parent_contract_id', $contract->parent_contract_id)->first();
        }

        if (! $contract->supplier_id) {
            return null;
        }

        $query->where('supplier_id', $contract->supplier_id);

        if ($contract->product) {
            return $query->where('product', $contract->product)->first();
        }

        return $query->where('name', $contract->name)->first();
    }

    /**
     * Returns ['recipients-key' => ['recipients' => [...], 'contracts' => Collection]].
     */
    private function groupByRecipients(Collection $contracts, array $fallback): array
    {
        $bags = [];

        foreach ($contracts as $contract) {
            $recipients = $this->resolveRecipients($contract, $fallback);
            $key        = implode(',', $recipients);

            if (! isset($bags[$key])) {
                $bags[$key] = [
                    'recipients' => $recipients,
                    'contracts'  => new Collection,
                ];
            }
            $bags[$key]['contracts']->push($contract);
        }

        return $bags;
    }

    private function resolveRecipients(Contract $contract, array $fallback): array
    {
        if ($contract->owner && filter_var($contract->owner->email, FILTER_VALIDATE_EMAIL)) {
            return [$contract->owner->email];
        }

        return $fallback;
    }

    private function markAlerted(Collection $contracts, string $window): void
    {
        $column = match ($window) {
            '14d'     => 'last_renewal_alert_14d_at',
            'expired' => 'last_renewal_alert_expired_at',
            default   => 'last_renewal_alert_30d_at',
        };

        Contract::whereIn('id', $contracts->pluck('id'))->update([$column => now()]);
    }
}
