<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\OverridableMailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mime\Email;

#[AllowDynamicProperties]
class WelcomeNotification extends Notification
{
    use Queueable, OverridableMailNotification;

    public $expire_date;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(public User $user)
    {
        $this->user->token = Password::broker('invites')->createToken($user);
        $this->user->expire_date = now()->addMinutes((int) config('auth.passwords.invites.expire', 2880))->format('F j, Y, g:i a');
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
            ->subject($this->overriddenSubject('account.welcome', '👋 '.trans('mail.welcome', ['name' => $this->user->first_name.' '.$this->user->last_name])))
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addTextHeader(
                    'X-System-Sender', 'Snipe-IT'
                );
            });

        return $this->applyBody($message, 'account.welcome', 'notifications.Welcome', $this->user->toArray());
    }
}
