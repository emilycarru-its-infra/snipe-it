<?php

namespace App\Services;

use App\Mail\StoreVendorOrderMail;
use App\Models\EmailTemplate;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sending approved store orders to the vendor as one order request.
 *
 * Several orders become a single email and a single consolidated CSV, which
 * is what CDW's desk wants: one request to key, sortable back into the
 * ECU-STORE references it came from.
 *
 * Lives here rather than in the queue controller because the API sends too,
 * and the two must not drift on the parts that matter — the readiness gate,
 * who the mail actually goes to, and the fact that a real send is the moment
 * sixteen orders become committed and sixteen people get told.
 */
class StoreVendorOrderDispatch
{
    /**
     * @param  Collection<int, StoreOrder>  $orders
     * @return array{sent: bool, error: ?string, recipients: array<int, string>, orders: Collection<int, StoreOrder>}
     */
    public function send(Collection $orders, User $actor, bool $test = false): array
    {
        if ($orders->isEmpty()) {
            return $this->failure(trans('admin/store/general.vendor_send_not_approved'));
        }

        // A test send is for checking the layout, so it goes out whatever
        // state the orders are in. A real one is refused without an
        // account: CDW places each line against a blanket purchase order
        // and cannot pick which, so sending anyway would buy nothing but a
        // round trip through their desk.
        if (! $test && $orders->contains(fn (StoreOrder $order) => ! $order->readyForVendor())) {
            return $this->failure(trans('admin/store/general.funding_required'));
        }

        if ($test) {
            $to = [$actor->email];
            $cc = [];
        } else {
            $to = EmailTemplate::recipientsFor('store.vendor_order', $orders->first()->supplier()?->order_emails);
            $cc = EmailTemplate::ccFor('store.vendor_order', 'devicesadmins@ecuad.ca,assetsadmins@ecuad.ca');
        }

        $to = array_values(array_filter($to));

        if ($to === []) {
            return $this->failure(trans('admin/store/general.vendor_send_no_recipients'));
        }

        try {
            $mail = Mail::to($to);

            if ($cc !== []) {
                $mail->cc($cc);
            }

            $mail->send(new StoreVendorOrderMail($orders, $test));
        } catch (\Throwable $e) {
            Log::warning('Vendor order email failed for store orders ['.$orders->pluck('id')->implode(',').']: '.$e->getMessage());

            return $this->failure(trans('admin/store/general.vendor_send_failed', ['error' => $e->getMessage()]));
        }

        // A test changes nothing — that is the whole point of it.
        if ($test) {
            return ['sent' => true, 'error' => null, 'recipients' => $to, 'orders' => $orders];
        }

        foreach ($orders as $order) {
            $order->update(['status' => 'ordered', 'vendor_sent_at' => now()]);
            StoreOrderNotifier::requester($order, 'ordered');
        }

        return ['sent' => true, 'error' => null, 'recipients' => $to, 'orders' => $orders];
    }

    /** @return array{sent: bool, error: string, recipients: array<int, string>, orders: Collection<int, StoreOrder>} */
    private function failure(string $error): array
    {
        return ['sent' => false, 'error' => $error, 'recipients' => [], 'orders' => collect()];
    }
}
