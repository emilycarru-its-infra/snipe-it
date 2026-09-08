<?php

namespace App\Notifications;

use App\Models\Component;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Concerns\BuildsTeamsCards;
use App\Services\Teams\TeamsCard;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\GoogleChat\Card;
use NotificationChannels\GoogleChat\GoogleChatChannel;
use NotificationChannels\GoogleChat\GoogleChatMessage;
use NotificationChannels\GoogleChat\Section;
use NotificationChannels\GoogleChat\Widgets\KeyValue;
use NotificationChannels\MicrosoftTeams\MicrosoftTeamsChannel;
use NotificationChannels\MicrosoftTeams\MicrosoftTeamsMessage;

#[AllowDynamicProperties]
class CheckoutComponentNotification extends Notification
{
    use BuildsTeamsCards;
    use Queueable;

    // The constructor assigns these; upstream leaves them undeclared and
    // relies on #[AllowDynamicProperties]. Declaring them costs nothing and
    // is what lets static analysis — and an editor — see them.
    public $settings;

    public $item;

    public $admin;

    public $note;

    public $target;

    private $params;

    /**
     * Create a new notification instance.
     *
     * @param  $params
     */
    public function __construct(Component $component, $checkedOutTo, User $checkedOutBy, $acceptance, $note)
    {
        $this->item = $component;
        $this->admin = $checkedOutBy;
        $this->note = $note;
        $this->target = $checkedOutTo;
        $this->acceptance = $acceptance;
        $this->qty = $component->checkout_qty;

        $this->settings = Setting::getSettings();
    }

    /**`
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via()
    {
        $notifyBy = [];
        if (Setting::getSettings()->webhook_selected == 'google' && Setting::getSettings()->webhook_endpoint) {

            $notifyBy[] = GoogleChatChannel::class;
        }

        if (Setting::getSettings()->webhook_selected == 'microsoft' && Setting::getSettings()->webhook_endpoint) {

            $notifyBy[] = MicrosoftTeamsChannel::class;
        }

        if (Setting::getSettings()->webhook_selected == 'slack' || Setting::getSettings()->webhook_selected == 'general') {
            $notifyBy[] = SlackWebhookChannel::class;
        }

        return $notifyBy;
    }

    public function toSlack()
    {
        $target = $this->target;
        $admin = $this->admin;
        $item = $this->item;
        $note = $this->note;
        $botname = ($this->settings->webhook_botname) ? $this->settings->webhook_botname : 'Snipe-Bot';
        $channel = ($this->settings->webhook_channel) ? $this->settings->webhook_channel : '';

        $fields = [
            trans('general.to') => '<'.$target->present()->viewUrl().'|'.$target->display_name.'>',
            trans('general.by') => '<'.$admin->present()->viewUrl().'|'.$admin->display_name.'>',
        ];

        if ($item->location) {
            $fields[trans('general.location')] = $item->location->name;
        }

        if ($item->company) {
            $fields[trans('general.company')] = $item->company->name;
        }

        return (new SlackMessage)
            ->content(':arrow_up: :package: '.trans('mail.Component_checkout_notification'))
            ->from($botname)
            ->to($channel)
            ->attachment(function ($attachment) use ($item, $note, $fields) {
                $attachment->title(htmlspecialchars_decode($item->display_name), $item->present()->viewUrl())
                    ->fields($fields)
                    ->content($note);
            });
    }

    /**
     * The card posted to Teams.
     */
    public function toTeamsCard(): TeamsCard
    {
        $item = $this->item;

        return $this->teamsCard(trans('mail.Component_checkout_notification'), 'accent', $this->admin)
            ->fact(trans('mail.assigned_to'), $this->teamsTargetName($this->target))
            ->fact(trans('admin/consumables/general.remaining'), $item->numRemaining())
            ->note($this->note)
            ->action(trans('general.teams_view_item'), $this->teamsUrl($item))
            ->action(trans('general.teams_view_asset'), $this->teamsUrl($this->target));
    }

    public function toMicrosoftTeams()
    {
        $target = $this->target;
        $admin = $this->admin;
        $item = $this->item;
        $note = $this->note;

        if (! Str::contains(Setting::getSettings()->webhook_endpoint, 'workflows')) {
            return MicrosoftTeamsMessage::create()
                ->to($this->settings->webhook_endpoint)
                ->type('success')
                ->addStartGroupToSection('activityTitle')
                ->title(trans('mail.Component_checkout_notification'))
                ->addStartGroupToSection('activityText')
                ->fact(htmlspecialchars_decode($item->display_name), '', 'activityTitle')
                ->fact(trans('mail.Component_checkout_notification').' by ', $admin->display_name)
                ->fact(trans('mail.assigned_to'), $target->display_name)
                ->fact(trans('admin/consumables/general.remaining'), $item->numRemaining())
                ->fact(trans('mail.notes'), $note ?: '');
        }

        $message = trans('mail.Component_checkout_notification');
        $details = [
            trans('mail.assigned_to') => $target->display_name,
            trans('mail.item') => htmlspecialchars_decode($item->display_name),
            trans('mail.Component_checkout_notification').' by' => $admin->display_name,
            trans('admin/consumables/general.remaining') => $item->numRemaining(),
            trans('mail.notes') => $note ?: '',
        ];

        return [$message, $details];
    }

    public function toGoogleChat()
    {
        $target = $this->target;
        $item = $this->item;
        $note = $this->note;

        return GoogleChatMessage::create()
            ->to($this->settings->webhook_endpoint)
            ->card(
                Card::create()
                    ->header(
                        '<strong>'.trans('mail.Component_checkout_notification').'</strong>' ?: '',
                        htmlspecialchars_decode($item->display_name) ?: '',
                    )
                    ->section(
                        Section::create(
                            KeyValue::create(
                                trans('mail.assigned_to') ?: '',
                                $target->display_name ?: '',
                                trans('admin/consumables/general.remaining').': '.$item->numRemaining(),
                            )
                                ->onClick(route('api.assets.show', $target->id))
                        )
                    )
            );

    }
}
