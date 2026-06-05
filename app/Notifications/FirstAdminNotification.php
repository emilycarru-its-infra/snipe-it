<?php

namespace App\Notifications;

use App\Notifications\Concerns\OverridableMailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

#[AllowDynamicProperties]
class FirstAdminNotification extends Notification
{
    use Queueable, OverridableMailNotification;

    private $_data = [];

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(array $content)
    {
        $this->_data['email'] = $content['email'];
        $this->_data['first_name'] = $content['first_name'];
        $this->_data['last_name'] = $content['last_name'];
        $this->_data['username'] = $content['username'];
        $this->_data['password'] = $content['password'];
        $this->_data['url'] = config('app.url');
    }

    /**
     * Get the notification's delivery channels.
     *
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
        $message = (new MailMessage)
            ->subject($this->overriddenSubject('account.first_admin', trans('mail.welcome', ['name' => $this->_data['first_name'].' '.$this->_data['last_name']])))
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addTextHeader(
                    'X-System-Sender', 'Snipe-IT'
                );
            });

        return $this->applyBody($message, 'account.first_admin', 'notifications.FirstAdmin', $this->_data);
    }
}
