<?php

namespace App\Console\Commands;

use App\Mail\UserAgreementSignatureReminderMail;
use App\Models\Actionlog;
use App\Models\UserAgreement;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 3-day nudge for outstanding signature requests. Runs daily; per
 * row, sends an email only when:
 *   - lifecycle_stage = 'agreement_sent'
 *   - signed_at is null
 *   - reminders_sent < forms.signature_reminders.max_reminders
 *   - last_reminder_sent_at (or sendForSignature time, if no
 *     reminder yet) is older than interval_days
 *
 * Each send increments `reminders_sent`, stamps
 * `last_reminder_sent_at`, and writes an actionlog row tagged
 * `user-agreement-reminder` so the trail survives in the activity
 * feed.
 *
 * --dry-run prints what would happen without sending or writing.
 */
class SendUserAgreementSignatureReminders extends Command
{
    protected $signature = 'snipeit:user-agreement-signature-reminders
                            {--dry-run : Report what would be sent without writing or emailing}';

    protected $description = 'Email users whose UserAgreements are awaiting signature past the configured interval.';

    public function handle(): int
    {
        if (! (bool) config('forms.signature_reminders.enabled', true)) {
            $this->info('Reminders disabled by config — nothing to do.');
            return self::SUCCESS;
        }

        $interval = (int) config('forms.signature_reminders.interval_days', 3);
        $maxCount = (int) config('forms.signature_reminders.max_reminders', 5);
        $cutoff   = Carbon::now()->subDays($interval);
        $dry      = (bool) $this->option('dry-run');

        $agreements = UserAgreement::query()
            ->where('lifecycle_stage', 'agreement_sent')
            ->whereNull('signed_at')
            ->where('reminders_sent', '<', $maxCount)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_reminder_sent_at')
                    ->orWhere('last_reminder_sent_at', '<=', $cutoff);
            })
            ->with(['user', 'asset.model'])
            ->get();

        if ($agreements->isEmpty()) {
            $this->info('Nothing to send — no agreements past the reminder window.');
            return self::SUCCESS;
        }

        $sent    = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($agreements as $agreement) {
            $tag = sprintf('FA#%d (user=%s, asset_tag=%s)',
                $agreement->id,
                $agreement->user?->email ?? 'no-user',
                $agreement->asset?->asset_tag ?? 'no-asset',
            );

            // For an initial reminder, also require the row to have
            // been at agreement_sent for at least interval_days —
            // protects against same-day reminders right after the
            // first email goes out (the row's updated_at proxies the
            // send time when no reminder has fired yet).
            if (! $agreement->last_reminder_sent_at
                && $agreement->updated_at
                && $agreement->updated_at->gt($cutoff)) {
                $this->line("[skip] {$tag} — agreement_sent less than {$interval}d ago");
                $skipped++;
                continue;
            }

            if (! $agreement->user || ! $agreement->user->email) {
                $this->warn("[skip] {$tag} — no user or no email");
                $skipped++;
                continue;
            }

            $next = (int) $agreement->reminders_sent + 1;

            if ($dry) {
                $this->line("[dry-run] would send reminder #{$next} for {$tag}");
                $sent++;
                continue;
            }

            try {
                Mail::to($agreement->user->email)
                    ->send(new UserAgreementSignatureReminderMail($agreement, $next));

                $agreement->reminders_sent       = $next;
                $agreement->last_reminder_sent_at = now();
                $agreement->saveQuietly();

                Actionlog::create([
                    'item_type'    => UserAgreement::class,
                    'item_id'      => $agreement->id,
                    'created_by'   => null,
                    'action_type'  => 'user-agreement-reminder',
                    'note'         => 'Reminder #'.$next.' sent to '.$agreement->user->email,
                    'target_id'    => $agreement->user_id,
                    'target_type'  => \App\Models\User::class,
                ]);

                $this->info("sent reminder #{$next} for {$tag}");
                $sent++;
            } catch (\Throwable $e) {
                $errors++;
                $this->error("failed {$tag}: ".$e->getMessage());
                Log::error('user-agreement-signature-reminders failed for '.$tag, ['exception' => $e]);
            }
        }

        $this->info(sprintf(
            'Done. sent=%d, skipped=%d, errors=%d (interval=%dd, max=%d)',
            $sent, $skipped, $errors, $interval, $maxCount,
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
