<?php

namespace App\Services;

use App\Mail\EmailDelivery;
use App\Mail\StoreOrderStatusMail;
use App\Models\EmailTemplate;
use App\Models\StoreOrder;
use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the requester-facing store lifecycle emails. Deliberately
 * fire-and-log: a mail transport hiccup must never roll back an order,
 * a decision, or a webhook update — the order state is the truth and
 * email is a courtesy on top of it.
 */
class StoreOrderNotifier
{
    /**
     * @param  array{tracking?: string, serials?: array<int, string>}  $extra
     */
    public static function requester(StoreOrder $order, string $event, array $extra = []): void
    {
        if (! in_array($event, StoreOrderStatusMail::EVENTS, true)) {
            return;
        }

        $key = 'store.'.$event;

        // The requester's own mail is untouched by delivery routing — they are
        // not in this Teams. Only the procurement copy moves.
        app(TeamsNotifier::class)->announce($key, self::card($order, $event, $extra));

        $email = $order->user?->email;

        if (! $email) {
            return;
        }

        try {
            $mail = Mail::to($email);

            // The "we received it" mail also lands with procurement, so
            // the queue never depends on someone remembering to look. A
            // decision copies them too: approving or declining is the
            // moment somebody may have to act, and it went to the requester
            // alone — so the note an approver wrote reached nobody on this
            // side and there was no record of the decision in a mailbox.
            // That copy is the half that moves to Teams. An address an admin
            // typed in explicitly is an explicit instruction and still gets
            // the mail; it is only the built-in default that stands down.
            $copiedToProcurement = EmailDelivery::shouldEmail($key) && in_array($event, ['requested', 'approved', 'declined'], true);

            $cc = EmailTemplate::ccFor(
                'store.'.$event,
                $copiedToProcurement ? 'devicesadmins@ecuad.ca,assetsadmins@ecuad.ca' : null,
            );

            if ($cc !== []) {
                $mail->cc($cc);
            }

            $mail->send(new StoreOrderStatusMail($order, $event, $extra));
        } catch (\Throwable $e) {
            Log::warning("Store order email [{$event}] failed for order {$order->id}: ".$e->getMessage());
        }
    }

    /**
     * The card procurement sees for a store order event.
     *
     * @param  array{tracking?: string, serials?: array<int, string>}  $extra
     */
    private static function card(StoreOrder $order, string $event, array $extra): TeamsCard
    {
        $accent = match ($event) {
            'approved', 'arrived' => 'good',
            'declined' => 'attention',
            'shipped', 'ordered' => 'warning',
            default => 'accent',
        };

        return TeamsCard::make('Store order — '.ucfirst($event))
            ->accent($accent)
            ->subtitle('#'.$order->id.' · '.($order->user?->getAttribute('display_name') ?? ''))
            ->facts([
                trans('general.status') => $order->status,
                'Tracking' => $extra['tracking'] ?? null,
                trans('general.qty') => $order->items()->count(),
            ])
            ->note($order->decision_notes ?? null)
            ->action('Open order', route('store.orders'));
    }
}
