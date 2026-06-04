<?php

namespace App\Notifications;

use AllowDynamicProperties;
use App\Notifications\Concerns\OverridableMailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

#[AllowDynamicProperties]
class InventoryAlert extends Notification
{
    use Queueable, OverridableMailNotification;

    private $params;

    /**
     * Create a new notification instance.
     */
    public function __construct($params, $threshold)
    {
        $this->items = $params;
        $this->threshold = $threshold ?? 0;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via()
    {
        return (! empty($this->items) && $this->threshold !== null) ? ['mail'] : [];

    }

    /**
     * Get the mail representation of the notification.
     *
     * @return MailMessage
     */
    public function toMail()
    {
        $data = [
            'items' => $this->items,
            'threshold' => $this->threshold,
        ];

        $message = (new MailMessage)
            ->subject($this->overriddenSubject('report.low_inventory', '⚠️ '.trans('mail.Low_Inventory_Report')))
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addTextHeader(
                    'X-System-Sender', 'Snipe-IT'
                );
            });

        return $this->applyBody($message, 'report.low_inventory', 'notifications.markdown.report-low-inventory', $data);
    }
}
