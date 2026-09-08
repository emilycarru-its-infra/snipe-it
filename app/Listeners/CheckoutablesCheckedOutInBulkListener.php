<?php

namespace App\Listeners;

use App\Events\CheckoutablesCheckedOutInBulk;
use App\Mail\BulkAssetCheckoutMail;
use App\Mail\EmailDelivery;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Setting;
use App\Models\User;
use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsNotifier;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckoutablesCheckedOutInBulkListener
{
    /** The registry key whose delivery routing governs the admin copy. */
    private const KEY = 'checkout.bulk_asset';

    public function subscribe($events)
    {
        $events->listen(
            CheckoutablesCheckedOutInBulk::class,
            CheckoutablesCheckedOutInBulkListener::class
        );
    }

    public function handle(CheckoutablesCheckedOutInBulk $event): void
    {
        $notifiableUser = $this->getNotifiableUser($event);

        $shouldSendEmailToUser = $this->shouldSendCheckoutEmailToUser($notifiableUser, $event->assets);
        $shouldSendEmailToAlertAddress = $this->shouldSendEmailToAlertAddress($event->assets);

        if ($shouldSendEmailToUser && $notifiableUser) {
            try {
                Mail::to($notifiableUser)->send(new BulkAssetCheckoutMail(
                    $event->assets,
                    $event->target,
                    $event->admin,
                    $event->checkout_at,
                    $event->expected_checkin,
                    $event->note,
                ));

                Log::info('BulkAssetCheckoutMail sent to checkout target');
            } catch (Exception $e) {
                Log::debug('Exception caught during BulkAssetCheckoutMail to target: '.$e->getMessage());
            }
        }

        if ($shouldSendEmailToAlertAddress && Setting::getSettings()->admin_cc_email
            && EmailDelivery::shouldEmail(self::KEY)) {
            try {
                Mail::to(Setting::getSettings()->admin_cc_email)->send(new BulkAssetCheckoutMail(
                    $event->assets,
                    $event->target,
                    $event->admin,
                    $event->checkout_at,
                    $event->expected_checkin,
                    $event->note,
                ));

                Log::info('BulkAssetCheckoutMail sent to admin_cc_email');
            } catch (Exception $e) {
                Log::debug('Exception caught during BulkAssetCheckoutMail to admin_cc_email: '.$e->getMessage());
            }
        }

        if ($shouldSendEmailToAlertAddress) {
            app(TeamsNotifier::class)->announce(self::KEY, $this->card($event));
        }
    }

    /**
     * The card for a bulk checkout. A bulk run is the one case where the
     * interesting content is the list itself, so the assets go in a table
     * rather than being summarised into a count.
     */
    private function card(CheckoutablesCheckedOutInBulk $event): TeamsCard
    {
        $target = $event->target;

        return TeamsCard::make(ucfirst(trans('general.assets_checked_out_count')))
            ->accent('accent')
            ->subtitle($target->getAttribute('display_name') ?? $target->getAttribute('name'))
            ->facts([
                trans('general.qty') => $event->assets->count(),
                trans('general.date') => $event->checkout_at,
                trans('general.expected_checkin') => $event->expected_checkin,
            ])
            ->note($event->note)
            ->table(
                [trans('general.asset_tag'), trans('general.name'), trans('admin/hardware/form.model')],
                $event->assets->map(fn (Asset $asset) => [
                    $asset->asset_tag,
                    $asset->name,
                    $asset->model?->getAttribute('name'),
                ])->all(),
            )
            ->footer($event->admin->getAttribute('display_name'));
    }

    private function shouldSendCheckoutEmailToUser(?User $user, Collection $assets): bool
    {
        if (! $user?->email) {
            return false;
        }

        if ($this->hasAssetWithEula($assets)) {
            return true;
        }

        if ($this->hasAssetWithCategorySettingToSendEmail($assets)) {
            return true;
        }

        return $this->hasAssetThatRequiresAcceptance($assets);
    }

    private function shouldSendEmailToAlertAddress(Collection $assets): bool
    {
        $setting = Setting::getSettings();

        if (! $setting) {
            return false;
        }

        if ($setting->admin_cc_always) {
            return true;
        }

        if (! $this->hasAssetThatRequiresAcceptance($assets)) {
            return false;
        }

        return (bool) $setting->admin_cc_email;
    }

    private function hasAssetWithEula(Collection $assets): bool
    {
        foreach ($assets as $asset) {
            if ($asset->getEula()) {
                return true;
            }
        }

        return false;
    }

    private function hasAssetWithCategorySettingToSendEmail(Collection $assets): bool
    {
        foreach ($assets as $asset) {
            if ($asset->checkin_email()) {
                return true;
            }
        }

        return false;
    }

    private function hasAssetThatRequiresAcceptance(Collection $assets): bool
    {
        foreach ($assets as $asset) {
            if ($asset->requireAcceptance()) {
                return true;
            }
        }

        return false;
    }

    private function getNotifiableUser(CheckoutablesCheckedOutInBulk $event): ?User
    {
        $target = $event->target;

        if ($target instanceof Asset) {
            $target->load('assignedTo');

            if ($target->assigned instanceof User) {
                return $target->assigned;
            }

            return null;
        }

        if ($target instanceof Location) {
            return $target->manager;
        }

        return $target;
    }
}
