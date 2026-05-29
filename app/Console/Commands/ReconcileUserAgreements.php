<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Services\UserAgreements\Reconciler;
use Illuminate\Console\Command;

/**
 * State-based reconciliation pass. Runs nightly; can be invoked
 * on-demand with `--user=ID` to refresh a single faculty member.
 *
 * What it does, idempotently:
 *   - missing `pickup`   row for an assigned faculty asset → create
 *   - device_cost > base AND missing `upgrade` row → create
 *   - asset's lease has ended AND missing `purchase` row → create
 *   - asset's lease has ended AND Snipe status is not the configured
 *     lease-end label → flip the status
 *
 * Use --dry-run for a "what would change today?" preview.
 */
class ReconcileUserAgreements extends Command
{
    protected $signature = 'snipeit:user-agreements-reconcile
                            {--user= : Limit to a single user_id (skip the full sweep)}
                            {--dry-run : Print the plan without writing}';

    protected $description = 'Ensure every faculty asset has the right UserAgreement rows for its current state.';

    public function handle(Reconciler $reconciler): int
    {
        // Honour the same alerts_enabled gate the other snipeit:*
        // commands use, so this respects the global kill-switch.
        if (Setting::getSettings()?->alerts_enabled !== 1) {
            $this->info('Global alerts_enabled is off — nothing to do.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');

        if ($userId = $this->option('user')) {
            $user = User::find($userId);
            if (! $user) {
                $this->error("User #{$userId} not found.");
                return self::FAILURE;
            }
            $reports = [$reconciler->reconcileForUser($user, $dry)];
        } else {
            $reports = $reconciler->reconcileAll($dry);
        }

        $userCount    = count($reports);
        $totals       = [
            'pickup'   => 0,
            'upgrade'  => 0,
            'purchase' => 0,
            'status'   => 0,
        ];
        $usersChanged = 0;

        foreach ($reports as $r) {
            if ($dry) {
                $totals['pickup']   += $r->plannedPickup;
                $totals['upgrade']  += $r->plannedUpgrade;
                $totals['purchase'] += $r->plannedPurchase;
                $totals['status']   += $r->plannedStatusFlip;
                if ($r->hasPlans()) {
                    $usersChanged++;
                    $this->line(sprintf(
                        '[dry-run] user=%d → pickup=%d upgrade=%d purchase=%d status=%d',
                        $r->userId, $r->plannedPickup, $r->plannedUpgrade, $r->plannedPurchase, $r->plannedStatusFlip,
                    ));
                }
            } else {
                $totals['pickup']   += $r->createdPickup;
                $totals['upgrade']  += $r->createdUpgrade;
                $totals['purchase'] += $r->createdPurchase;
                $totals['status']   += $r->statusFlipped;
                if ($r->hasChanges()) {
                    $usersChanged++;
                    $this->info(sprintf(
                        'user=%d → pickup=%d upgrade=%d purchase=%d status=%d rows=%s',
                        $r->userId, $r->createdPickup, $r->createdUpgrade, $r->createdPurchase, $r->statusFlipped,
                        implode(',', $r->createdRowIds) ?: '-',
                    ));
                }
            }
        }

        $this->info(sprintf(
            'Done. users_scanned=%d users_changed=%d pickup=%d upgrade=%d purchase=%d status=%d',
            $userCount, $usersChanged, $totals['pickup'], $totals['upgrade'], $totals['purchase'], $totals['status'],
        ));

        return self::SUCCESS;
    }
}
