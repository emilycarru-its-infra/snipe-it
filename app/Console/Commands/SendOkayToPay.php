<?php

namespace App\Console\Commands;

use App\Services\Leasing\OkayToPay;
use Illuminate\Console\Command;

/**
 * The scheduled half of OkayToPay: send queued lessor sign-offs whose review
 * window has ended (or that someone approved), hold the ones someone
 * disputed, and post the day-before reminder. A no-op while the feature is
 * off, and safe to run as often as the scheduler likes.
 */
class SendOkayToPay extends Command
{
    protected $signature = 'snipeit:okay-to-pay';

    protected $description = 'Send due OK-to-pay sign-offs to the lessor and post their Teams reminders.';

    public function handle(OkayToPay $okayToPay): int
    {
        $counts = $okayToPay->dispatchDue();

        $this->info(sprintf('mode=%s sent=%d held=%d reminded=%d', $okayToPay->mode(), $counts['sent'], $counts['held'], $counts['reminded']));

        return self::SUCCESS;
    }
}
