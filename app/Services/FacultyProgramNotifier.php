<?php

namespace App\Services;

use App\Mail\FacultyProgramSubmissionMail;
use App\Models\EmailTemplate;
use App\Models\UserAgreement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the program that somebody applied.
 *
 * Fire-and-log, like StoreOrderNotifier and for the same reason: the
 * UserAgreement is the record of the application, and a mail transport
 * problem must never cost somebody their submission or bounce them back
 * to a form they have already filled in.
 */
class FacultyProgramNotifier
{
    /** Where applications land when nobody has configured it otherwise. */
    private const DEFAULT_RECIPIENTS = 'devicesadmins@ecuad.ca,assetsadmins@ecuad.ca';

    public static function submitted(UserAgreement $pickup, ?UserAgreement $buyout, bool $updated): void
    {
        $recipients = EmailTemplate::recipientsFor(
            FacultyProgramSubmissionMail::KEY,
            self::DEFAULT_RECIPIENTS,
        );

        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send(new FacultyProgramSubmissionMail($pickup, $buyout, $updated));
        } catch (\Throwable $e) {
            Log::warning("Faculty program submission email failed for agreement {$pickup->id}: ".$e->getMessage());
        }
    }
}
