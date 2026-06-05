<?php

namespace App\Notifications;

use App\Helpers\Helper;
use App\Notifications\Concerns\OverridableMailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

#[AllowDynamicProperties]
class ExpectedCheckinAdminNotification extends Notification
{
    use Queueable, OverridableMailNotification;

    private $params;

    /**
     * Create a new notification instance.
     */
    public function __construct($params)
    {
        $this->assets = $params;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via()
    {
        $notifyBy = [];
        $notifyBy[] = 'mail';

        return $notifyBy;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return MailMessage
     */
    public function toMail()
    {
        // Flat rows so an admin's override body can template the digest with
        // {{#each rows}} without needing the in-view date/route helpers.
        $rows = collect($this->assets)->map(fn ($asset) => [
            'asset_tag' => $asset->asset_tag,
            'name' => $asset->display_name,
            'assigned_to' => $asset->assignedTo?->display_name ?? trans('general.unknown_user'),
            'expected_checkin' => Helper::getFormattedDateObject($asset->expected_checkin, 'date')['formatted'] ?? '',
        ])->all();

        $data = [
            'assets' => $this->assets,
            'rows' => $rows,
        ];

        $message = (new MailMessage)
            ->subject($this->overriddenSubject('report.expected_checkin', trans('mail.Expected_Checkin_Report')))
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addTextHeader(
                    'X-System-Sender', 'Snipe-IT'
                );
            });

        return $this->applyBody($message, 'report.expected_checkin', 'notifications.markdown.report-expected-checkins', $data);
    }
}
