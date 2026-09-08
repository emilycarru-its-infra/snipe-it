<?php

namespace App\Services;

use App\Mail\EmailDelivery;
use App\Mail\FacultyProgramSubmissionMail;
use App\Models\EmailTemplate;
use App\Models\UserAgreement;
use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsNotifier;
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
        $key = FacultyProgramSubmissionMail::KEY;

        $recipients = EmailTemplate::recipientsFor($key, self::DEFAULT_RECIPIENTS);

        if ($recipients !== [] && EmailDelivery::shouldEmail($key)) {
            try {
                Mail::to($recipients)->send(new FacultyProgramSubmissionMail($pickup, $buyout, $updated));
            } catch (\Throwable $e) {
                Log::warning("Faculty program submission email failed for agreement {$pickup->id}: ".$e->getMessage());
            }
        }

        app(TeamsNotifier::class)->announce($key, self::card($pickup, $buyout, $updated));
    }

    /**
     * The card the program sees. An edited application says so in the title —
     * a second card about the same person otherwise reads as a duplicate.
     */
    private static function card(UserAgreement $pickup, ?UserAgreement $buyout, bool $updated): TeamsCard
    {
        $applicant = $pickup->user;
        $asset = $pickup->asset;

        return TeamsCard::make($updated ? 'Faculty Laptop Program application updated' : 'Faculty Laptop Program application')
            ->accent($updated ? 'warning' : 'accent')
            ->subtitle($applicant?->display_name)
            ->facts([
                trans('general.department') => $applicant?->department?->name,
                trans('general.asset_tag') => $asset?->asset_tag,
                trans('admin/hardware/form.serial') => $asset?->serial,
                trans('admin/hardware/form.model') => $asset?->model?->name,
                'Buyout requested' => $buyout !== null,
            ])
            ->action(trans('general.teams_view_user'), $applicant ? route('users.show', $applicant->id) : null)
            ->action(trans('general.teams_view_asset'), $asset ? route('hardware.show', $asset->id) : null);
    }
}
