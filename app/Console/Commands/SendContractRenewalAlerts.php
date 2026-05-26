<?php

namespace App\Console\Commands;

use App\Mail\ContractRenewalAlertMail;
use App\Models\Contract;
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
        $fallback = collect(explode(',', (string) $settings->alert_email))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();

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
            ->whereNotNull('end_date');

        return match ($window) {
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
