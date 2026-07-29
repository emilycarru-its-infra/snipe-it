<?php

namespace App\Services;

use App\Mail\StoreOrderStatusMail;
use App\Models\EmailTemplate;
use App\Models\StoreOrder;
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
        $email = $order->user?->email;

        if (! $email || ! in_array($event, StoreOrderStatusMail::EVENTS, true)) {
            return;
        }

        try {
            $mail = Mail::to($email);

            // The "we received it" mail also lands with procurement, so
            // the queue never depends on someone remembering to look.
            $cc = EmailTemplate::ccFor('store.'.$event, $event === 'requested' ? 'devicesadmins@ecuad.ca,assetsadmins@ecuad.ca' : null);

            if ($cc !== []) {
                $mail->cc($cc);
            }

            $mail->send(new StoreOrderStatusMail($order, $event, $extra));
        } catch (\Throwable $e) {
            Log::warning("Store order email [{$event}] failed for order {$order->id}: ".$e->getMessage());
        }
    }
}
