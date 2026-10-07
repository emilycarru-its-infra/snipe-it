<?php

namespace App\Console;

use App\Models\Setting;
use App\Services\Settings\Preferences;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * The fork's own jobs run at the times in the schedule.* preferences,
     * read each time the schedule is built, so moving one needs no deploy.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        if (Setting::getSettings()?->alerts_enabled === 1) {
            $schedule->command('snipeit:inventory-alerts')->daily();
            $schedule->command('snipeit:expiring-alerts')->daily();
            $schedule->command('snipeit:expected-checkin')->daily();
            $schedule->command('snipeit:upcoming-audits')->daily();
            $schedule->command('snipeit:contract-renewals')->dailyAt(self::at('schedule.contract_renewals'));
            $schedule->command('snipeit:user-pregen-pdfs')->dailyAt(self::at('schedule.user_pregen_pdfs'));
            $schedule->command('snipeit:user-agreement-signature-reminders')->dailyAt(self::at('schedule.signature_reminders'));
            $schedule->command('snipeit:user-agreements-reconcile')->dailyAt(self::at('schedule.user_agreements_reconcile'));
        }
        // Weekdays, and outside the alerts_enabled gate: this is the list of
        // what procurement still has to do, not an optional alert.
        $schedule->command('snipeit:procurement-actions')->weekdays()->dailyAt(self::at('schedule.procurement_actions'));

        $schedule->command('snipeit:backup')->weekly();
        $schedule->command('backup:clean')->daily();
        $schedule->command('auth:clear-resets')->everyFifteenMinutes();

        // Lessor OK-to-pay sign-offs: send what is due, remind the day before.
        // A no-op unless LEASING_OKP_MODE is review or auto.
        $minutes = max(1, min(59, (int) Preferences::get('schedule.okay_to_pay_minutes')));
        $schedule->command('snipeit:okay-to-pay')->cron("*/{$minutes} * * * *")->withoutOverlapping();
        $schedule->command('saml:clear_expired_nonces')->weekly();

        // Nightly toner ↔ printer compatibility backfill. Idempotent
        // (syncWithoutDetaching), so adding a new printer model or toner
        // consumable will get wired up automatically — no code change or
        // redeploy needed. Known SKU mismatches that the auto-needle
        // pipeline can't bridge are passed as explicit --alias pairs here.
        $schedule->command('consumables:link-printer-models', [
            '--alias' => ['IM C3500=Ricoh IM C3510'],
        ])->dailyAt(self::at('schedule.link_printer_models'));

        // Nightly lessor backfill. Idempotent and non-destructive (only fills a
        // null lessor_id from the Lease Contract ID prefix), so newly-ingested
        // leases pick up their lessor without a code change or manual step.
        $schedule->command('snipeit:backfill-lessors', ['--write' => true])->dailyAt(self::at('schedule.backfill_lessors'));

        // Nightly ownership reconcile. Buyouts are still recorded by setting a
        // status out of long habit, and ownership_type is what the lease
        // reports read, so this keeps the two in step until those statuses are
        // retired — after which it is simply a no-op.
        $schedule->command('snipeit:reconcile-lease-ownership', ['--write' => true])->dailyAt(self::at('schedule.reconcile_lease_ownership'));

        // Nightly lease-name mirror from the contracts register. The asset-side
        // name is derived data with no writer of its own, so it had drifted off
        // every leased asset; running it nightly means renaming a contract
        // propagates instead of leaving the fleet on a stale name.
        $schedule->command('snipeit:sync-lease-names', ['--write' => true])->dailyAt(self::at('schedule.sync_lease_names'));

        // Weekly sweep of the legacy LIC-* license-migration contracts. New
        // TDX contracts keep landing for products that still have legacy rows
        // on file, and each pair renews as two contracts until one is retired
        // — so this has to keep running, not just clear the original backlog.
        $schedule->command('contracts:reconcile-legacy-licenses', ['--write' => true])->weeklyOn(1, self::at('schedule.reconcile_legacy_licenses'));

        // Weekly catalog refresh from Apple's Canadian store: retail
        // prices land as estimates (quotes always outrank them) and spec
        // columns are corrected from Apple's own configuration data.
        $schedule->command('catalog:sync-apple')->weeklyOn(1, self::at('schedule.catalog_sync_apple'));
    }

    /** A schedule.* preference as an HH:MM time, falling back to its default if a stored value is unusable. */
    private static function at(string $key): string
    {
        $time = (string) Preferences::get($key);

        return Preferences::isTime($time) ? $time : (string) Preferences::defaultFor($key);
    }

    /**
     * This method is required by Laravel to handle any console routes
     * that are defined in routes/console.php.
     */
    protected function commands()
    {
        require base_path('routes/console.php');
        $this->load(__DIR__.'/Commands');
    }
}
