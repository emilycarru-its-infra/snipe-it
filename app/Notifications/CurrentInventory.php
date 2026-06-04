<?php

namespace App\Notifications;

use App\Notifications\Concerns\OverridableMailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

#[AllowDynamicProperties]
class CurrentInventory extends Notification
{
    use Queueable, OverridableMailNotification;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via()
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return MailMessage
     */
    public function toMail()
    {
        $data = [
            'assets' => $this->user->assets,
            'accessories' => $this->user->accessories,
            'licenses' => $this->user->licenses,
            'consumables' => $this->user->consumables,
        ];

        $message = (new MailMessage)
            ->subject($this->overriddenSubject('account.inventory', trans('mail.inventory_report')))
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addTextHeader(
                    'X-System-Sender', 'Snipe-IT'
                );
            });

        return $this->applyBody($message, 'account.inventory', 'notifications.markdown.user-inventory', $data);
    }
}
