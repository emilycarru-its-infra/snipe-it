<?php

namespace App\Notifications;

use AllowDynamicProperties;
use App\Models\Setting;
use App\Notifications\Concerns\BuildsTeamsCards;
use App\Notifications\Concerns\OverridableMailNotification;
use App\Services\Teams\TeamsCard;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

#[AllowDynamicProperties]
class AcceptanceItemAcceptedNotification extends Notification
{
    use BuildsTeamsCards;
    use OverridableMailNotification, Queueable;

    // The constructor assigns these; upstream leaves them undeclared and
    // relies on #[AllowDynamicProperties]. Declaring them costs nothing and
    // is what lets static analysis — and an editor — see them.
    public $settings;

    public $item_tag;

    public $item_name;

    public $item_model;

    public $item_serial;

    public $item_status;

    public $accepted_date;

    public $assigned_to;

    public $company_name;

    public $file;

    public $qty;

    public $note;

    public $custom_fields;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($params)
    {
        $this->item_tag = $params['item_tag'];
        $this->item_name = $params['item_name'];
        $this->item_model = $params['item_model'];
        $this->item_serial = $params['item_serial'];
        $this->item_status = $params['item_status'];
        $this->accepted_date = $params['accepted_date'];
        $this->assigned_to = $params['assigned_to'];
        $this->company_name = $params['company_name'];
        $this->settings = Setting::getSettings();
        $this->file = $params['file'] ?? null;
        $this->qty = $params['qty'] ?? null;
        $this->note = $params['note'] ?? null;
        $this->custom_fields = $params['custom_fields'] ?? [];

    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via()
    {

        $notifyBy = ['mail'];

        return $notifyBy;

    }

    public function shouldSend($notifiable, $channel)
    {
        return $this->settings->alerts_enabled && ! empty($this->settings->alert_email);
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    /**
     * The card posted to Teams in place of the admin's copy of this email.
     */
    public function toTeamsCard(): TeamsCard
    {
        return $this->teamsCard('Item accepted', 'good')
            ->subtitle($this->item_name)
            ->facts([
                trans('mail.assigned_to') => $this->assigned_to,
                trans('general.asset_tag') => $this->item_tag,
                trans('admin/hardware/form.serial') => $this->item_serial,
                trans('admin/hardware/form.model') => $this->item_model,
                trans('admin/hardware/form.status') => $this->item_status,
                trans('general.qty') => $this->qty,
                trans('general.date') => $this->accepted_date,
            ])
            ->note($this->note);
    }

    public function toMail()
    {
        $data = [
            'item_tag' => $this->item_tag,
            'item_name' => $this->item_name,
            'item_model' => $this->item_model,
            'item_serial' => $this->item_serial,
            'item_status' => $this->item_status,
            'note' => $this->note,
            'accepted_date' => $this->accepted_date,
            'assigned_to' => $this->assigned_to,
            'company_name' => $this->company_name,
            'qty' => $this->qty,
            'custom_fields' => $this->custom_fields,
            'intro_text' => trans('mail.acceptance_accepted_greeting', ['user' => $this->assigned_to, 'item' => $this->item_name]),
        ];

        $message = (new MailMessage)
            ->subject($this->overriddenSubject('acceptance.accepted_admin', trans('mail.acceptance_accepted', ['user' => $this->assigned_to, 'item' => $this->item_name])))
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addTextHeader(
                    'X-System-Sender', 'Snipe-IT'
                );
            });

        return $this->applyBody($message, 'acceptance.accepted_admin', 'notifications.markdown.asset-acceptance', $data);
    }
}
