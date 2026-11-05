<?php

namespace App\Services\UserAgreements;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserAgreement;
use App\Notifications\AcceptanceItemAcceptedToUserNotification;
use Illuminate\Validation\ValidationException;
use Throwable;

/** The acceptance flow and the admin recovery action send the same configured email. */
class PickupInstructionsSender
{
    public function send(UserAgreement $agreement, bool $includePdf = false, bool $resend = false): bool
    {
        $agreement->refresh()->loadMissing(['user', 'asset.model', 'asset.status', 'asset.company', 'checkoutAcceptance']);
        if ($agreement->deployed_at) {
            throw ValidationException::withMessages(['agreement' => trans('admin/user-agreements/message.pickup_already_collected')]);
        }
        $acceptance = $agreement->checkoutAcceptance;
        if ($agreement->agreement_type !== 'pickup' || $agreement->isApplication()
            || ! in_array($agreement->lifecycle_stage, ['quoted', 'agreement_sent', 'agreement_signed'], true)
            || ! $agreement->user || ! $agreement->user->email || ! $agreement->asset
            || ! $acceptance?->accepted_at || $acceptance->declined_at
            || (int) $acceptance->assigned_to_id !== (int) $agreement->user_id
            || (int) $acceptance->checkoutable_id !== (int) $agreement->asset_id
            || $acceptance->checkoutable_type !== Asset::class) {
            throw ValidationException::withMessages(['agreement' => trans('admin/user-agreements/message.pickup_requires_acceptance')]);
        }

        $url = EmailTemplate::optionFor('agreement.pickup_accepted', 'booking_url', config('forms.pickup_auto_create.booking_url'));
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['booking_url' => trans('admin/user-agreements/message.pickup_requires_booking_url')]);
        }

        // Claim with one UPDATE, without holding a transaction during SMTP.
        // An interrupted process releases its claim after ten minutes.
        $claimedAt = now();
        $claim = UserAgreement::whereKey($agreement->id)
            ->when(! $resend, fn ($query) => $query->whereNull('pickup_instructions_sent_at'))
            ->where(fn ($query) => $query->whereNull('pickup_instructions_sending_at')
                ->orWhere('pickup_instructions_sending_at', '<', now()->subMinutes(10)));
        if (! $claim->update(['pickup_instructions_sending_at' => $claimedAt])) {
            return false;
        }

        try {
            $asset = $agreement->asset;
            $notification = (new AcceptanceItemAcceptedToUserNotification([
                'pickup_agreement' => true,
                'item_tag' => $asset->asset_tag,
                'item_name' => $asset->getDisplayNameAttribute(),
                'item_model' => $asset->model?->name,
                'item_serial' => $asset->serial,
                'item_status' => $asset->status?->name,
                'accepted_date' => $acceptance->accepted_at->format('Y-m-d H:i:s'),
                'assigned_to' => $agreement->user->display_name,
                'company_name' => $asset->company->name ?? Setting::getSettings()->site_name,
                'qty' => $acceptance->qty ?? 1,
                'note' => $acceptance->note,
                'file' => $includePdf ? $acceptance->stored_eula_file : null,
            ]))->locale($agreement->user->locale);
            $html = html_entity_decode((string) $notification->toMail()->render(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! str_contains($html, $url)) {
                throw ValidationException::withMessages(['booking_url' => trans('admin/user-agreements/message.pickup_requires_booking_in_body')]);
            }
            $agreement->user->notify($notification);

            UserAgreement::whereKey($agreement->id)->where('pickup_instructions_sending_at', $claimedAt)
                ->update(['pickup_instructions_sent_at' => now(), 'pickup_instructions_sending_at' => null]);
        } catch (Throwable $e) {
            UserAgreement::whereKey($agreement->id)->where('pickup_instructions_sending_at', $claimedAt)
                ->update(['pickup_instructions_sending_at' => null]);
            throw $e;
        }

        $log = new Actionlog;
        $log->item_type = UserAgreement::class;
        $log->item_id = $agreement->id;
        $log->target_type = User::class;
        $log->target_id = $agreement->user_id;
        $log->setAttribute('created_by', auth()->id());
        $log->note = 'Pickup scheduling instructions emailed to the agreement recipient.';
        try {
            $log->logaction(ActionType::NoteAdded);
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }
}
