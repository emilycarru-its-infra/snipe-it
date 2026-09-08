<?php

namespace App\Notifications;

use App\Helpers\Helper;
use App\Models\Setting;
use App\Notifications\Concerns\BuildsTeamsCards;
use App\Notifications\Concerns\OverridableMailNotification;
use App\Services\Teams\TeamsCard;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

#[AllowDynamicProperties]
class RequestAssetCancelation extends Notification
{
    use BuildsTeamsCards;
    use OverridableMailNotification;

    private $params;

    /**
     * Create a new notification instance.
     */
    public function __construct($params)
    {
        $this->target = $params['target'];
        $this->item = $params['item'];
        $this->note = '';
        $this->last_checkout = '';
        $this->item_quantity = $params['item_quantity'];
        $this->expected_checkin = '';
        $this->requested_date = Helper::getFormattedDateObject($params['requested_date'], 'datetime',
            false);
        $this->settings = Setting::getSettings();

        if (array_key_exists('note', $params)) {
            $this->note = $params['note'];
        }

        if ($this->item->last_checkout) {
            $this->last_checkout = Helper::getFormattedDateObject($this->item->last_checkout, 'date',
                false);
        }

        if ($this->item->expected_checkin) {
            $this->expected_checkin = Helper::getFormattedDateObject($this->item->expected_checkin, 'date',
                false);
        }
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via()
    {
        $notifyBy = [];

        if (Setting::getSettings()->webhook_endpoint != '') {
            Log::debug('use webhook');
            $notifyBy[] = 'slack';
        }

        $notifyBy[] = 'mail';

        return $notifyBy;
    }

    /**
     * The card posted to Teams in place of this email. Requests are actioned
     * by staff, so the buttons matter more than usual — the point of the card
     * is to get from "someone asked" to the item's page in one click.
     */
    public function toTeamsCard(): TeamsCard
    {
        $item = $this->item;

        return $this->teamsCard('Asset request canceled', 'warning')
            ->subtitle(htmlspecialchars_decode((string) $item->display_name))
            ->facts([
                'Requested by' => $this->teamsTargetName($this->target),
                trans('general.asset_tag') => $item->asset_tag ?? null,
                trans('general.qty') => $this->item_quantity,
                trans('general.date') => $this->requested_date,
                trans('general.expected_checkin') => $this->expected_checkin,
            ])
            ->note($this->note)
            ->action(trans('general.teams_view_asset'), $this->teamsUrl($item))
            ->action(trans('general.teams_view_user'), $this->teamsUrl($this->target));
    }

    public function toSlack()
    {
        $target = $this->target;
        $item = $this->item;
        $note = $this->note;
        $qty = $this->item_quantity;
        $botname = ($this->settings->webhook_botname) ? $this->settings->webhook_botname : 'Snipe-Bot';
        $channel = ($this->settings->webhook_channel) ? $this->settings->webhook_channel : '';

        $fields = [
            'QTY' => $qty,
            'Canceled By' => '<'.$target->present()->viewUrl().'|'.$target->display_name.'>',
        ];

        if (($this->expected_checkin) && ($this->expected_checkin != '')) {
            $fields['Expected Checkin'] = $this->expected_checkin;
        }

        return (new SlackMessage)
            ->content(trans('mail.a_user_canceled'))
            ->from($botname)
            ->to($channel)
            ->attachment(function ($attachment) use ($item, $note, $fields) {
                $attachment->title(htmlspecialchars_decode($item->display_name), $item->present()->viewUrl())
                    ->fields($fields)
                    ->content($note);
            });
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail()
    {
        $fields = [];

        // Check if the item has custom fields associated with it
        if (($this->item->model) && ($this->item->model->fieldset)) {
            $fields = $this->item->model->fieldset->fields;
        }

        $data = [
            'item' => $this->item,
            'note' => $this->note,
            'requested_by' => $this->target,
            'requested_date' => $this->requested_date,
            'fields' => $fields,
            'qty' => $this->item_quantity,
            'last_checkout' => $this->last_checkout,
            'expected_checkin' => $this->expected_checkin,
            'intro_text' => trans('mail.a_user_canceled'),
        ];

        $message = (new MailMessage)
            ->subject($this->overriddenSubject('request.cancel', trans('general.request_canceled')))
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addTextHeader(
                    'X-System-Sender', 'Snipe-IT'
                );
            });

        return $this->applyBody($message, 'request.cancel', 'notifications.markdown.asset-requested', $data);
    }
}
