<?php

namespace App\Notifications;

use AllowDynamicProperties;
use App\Helpers\Helper;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Notifications\Concerns\OverridableMailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

#[AllowDynamicProperties]
class AcceptanceItemAcceptedToUserNotification extends Notification
{
    use OverridableMailNotification, Queueable;

    public bool $pickup_agreement;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($params)
    {
        $this->pickup_agreement = (bool) ($params['pickup_agreement'] ?? false);
        $this->item_tag = $params['item_tag'];
        $this->item_name = $params['item_name'];
        $this->item_model = $params['item_model'];
        $this->item_serial = $params['item_serial'];
        $this->item_status = $params['item_status'];
        $this->accepted_date = Helper::getFormattedDateObject($params['accepted_date'], 'datetime', false);
        $this->assigned_to = $params['assigned_to'];
        $this->note = $params['note'] ?? null;
        $this->company_name = $params['company_name'];
        $this->settings = Setting::getSettings();
        $this->file = $params['file'] ?? null;
        $this->qty = $params['qty'] ?? null;
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

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail()
    {
        $key = $this->pickup_agreement ? 'agreement.pickup_accepted' : 'acceptance.accepted_user';
        $bookingUrl = $this->pickup_agreement
            ? EmailTemplate::optionFor($key, 'booking_url', config('forms.pickup_auto_create.booking_url'))
            : null;
        if (! is_string($bookingUrl) || filter_var($bookingUrl, FILTER_VALIDATE_URL) === false || parse_url($bookingUrl, PHP_URL_SCHEME) !== 'https') {
            $bookingUrl = null;
        }
        $pdf_path = storage_path('private_uploads/eula-pdfs/'.$this->file);
        $data = [
            'booking_url' => $bookingUrl,
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
            'intro_text' => trans_choice('mail.acceptance_asset_accepted_to_user', $this->qty, ['qty' => $this->qty, 'site_name' => $this->settings->site_name]),
        ];

        $subject = $this->pickup_agreement ? trans('mail.pickup_booking_subject') : $data['intro_text'];
        $message = (new MailMessage)
            ->subject($this->overriddenSubject($key, $subject))
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addTextHeader(
                    'X-System-Sender', 'Snipe-IT'
                );
            });

        $message = $this->applyBody($message, $key, 'notifications.markdown.asset-acceptance', $data);

        // Attach the signed-agreement PDF when present (absent in hub previews/test-sends).
        if ($this->file && is_file($pdf_path)) {
            $message->attach($pdf_path);
        }

        return $message;
    }
}
