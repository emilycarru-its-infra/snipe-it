<?php

namespace App\Console;

use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
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
            $schedule->command('snipeit:contract-renewals')->dailyAt('07:30');
            $schedule->command('snipeit:user-pregen-pdfs')->dailyAt('05:00');
            $schedule->command('snipeit:user-agreement-signature-reminders')->dailyAt('06:00');
            $schedule->command('snipeit:user-agreements-reconcile')->dailyAt('04:30');
        }
        $schedule->command('snipeit:backup')->weekly();
        $schedule->command('backup:clean')->daily();
        $schedule->command('auth:clear-resets')->everyFifteenMinutes();
        $schedule->command('saml:clear_expired_nonces')->weekly();

        // Nightly toner ↔ printer compatibility backfill. Idempotent
        // (syncWithoutDetaching), so adding a new printer model or toner
        // consumable will get wired up automatically — no code change or
        // redeploy needed. Known SKU mismatches that the auto-needle
        // pipeline can't bridge are passed as explicit --alias pairs here.
        $schedule->command('consumables:link-printer-models', [
            '--alias' => ['IM C3500=Ricoh IM C3510'],
        ])->dailyAt('02:30');

        // Nightly lessor backfill. Idempotent and non-destructive (only fills a
        // null lessor_id from the Lease Contract ID prefix), so newly-ingested
        // leases pick up their lessor without a code change or manual step.
        $schedule->command('snipeit:backfill-lessors', ['--write' => true])->dailyAt('03:15');
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
