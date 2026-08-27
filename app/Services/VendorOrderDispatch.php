<?php

namespace App\Services;

use App\Mail\PurchaseOrderQuoteAcceptanceMail;
use App\Mail\RequisitionVendorOrderMail;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Placing an order with the vendor, and answering what they send back.
 *
 * The order is one vendor order under a purchase order — the purchase order is
 * the budget, and sees several of these; each carries its own lines, account,
 * quote and loop.
 *
 * The web page and the API both do this, and the two must not drift on the
 * parts that matter: the readiness gates, who the mail actually reaches, and
 * the rule that only a real send is a send. So the decisions live here and
 * both controllers are thin — one redirects with a flash, the other answers
 * JSON, and neither knows how an order gets to CDW.
 *
 * The vendor's loop, as their rep set it out: we send → they answer with
 * changes → we accept → they send the final quote → we accept it → they
 * issue an order number. `send()` is the first step; `respond()` records the
 * rest. Accepting the final quote is the point of no return, and it is also
 * the one step the vendor has to hear about — a quote nobody answers is a
 * quote nobody places — so `respond()` can send that acceptance, and only
 * stamps the order accepted once the acceptance has actually gone.
 */
class VendorOrderDispatch
{
    public function __construct(private CatalogQuoteWriteback $catalog) {}

    /** The facts recorded on a send, whether or not the send succeeds. */
    public const SEND_FIELDS = ['quote_number', 'quote_total', 'quote_expires_at', 'funding_account', 'lease_schedule', 'order_cc'];

    /** The vendor's answer, recorded alongside whichever step it came with. */
    public const RESPONSE_FIELDS = ['quote_number', 'quote_total', 'quote_expires_at'];

    /**
     * Record the account and quote, then put the order to the vendor.
     *
     * `$fields` is whatever the caller validated of {@see SEND_FIELDS} plus an
     * optional `cc_users` list of user ids. A key present with an empty value
     * is left alone, except `cc_users`, where an empty submission clears the
     * list — that is what unticking everyone means.
     *
     * @param  array<string, mixed>  $fields
     * @return array{sent: bool, error: ?string, recipients: array<int, string>, test: bool}
     */
    public function send(Order $order, User $actor, array $fields = [], bool $test = false): array
    {
        $order->load('supplier', 'purchaseOrder', 'items.catalogItem');

        // The quote and the account are recorded whether or not the send
        // succeeds: they are facts about the order — one from the vendor, one
        // our own decision — not side effects of emailing anybody.
        foreach (self::SEND_FIELDS as $field) {
            if (array_key_exists($field, $fields) && filled($fields[$field])) {
                $order->{$field} = $fields[$field];
            }
        }

        // Picked people are stored as ids, so a name change or a new address
        // follows them.
        if (array_key_exists('cc_users', $fields)) {
            $order->order_cc_users = implode(',', $fields['cc_users'] ?? []);
        }

        if ($order->isDirty()) {
            $order->save();
        }

        // CDW places every line against a purchase order number, so an order
        // with no purchase order behind it — or no lines — is not sendable.
        if ($order->status === 'cancelled' || ! $order->purchaseOrder || $order->vendorOrderLines()->isEmpty()) {
            return $this->failure(trans('admin/purchase-orders/general.vendor_send_needs_po'), $test);
        }

        if (! $order->fundingResolved()) {
            return $this->failure(trans(SupplierAccounts::needsSchedule($order->funding_account)
                ? 'admin/store/general.funding_lease_needs_schedule'
                : 'admin/purchase-orders/general.vendor_send_needs_account'), $test);
        }

        // Both part numbers on every line. The MFR# identifies the product
        // and the EDC is what CDW places, so a line short of either is not an
        // order they can fill. Named rather than counted: the fix is a catalog
        // row somebody has to go and complete.
        if (($missing = $order->linesMissingPartNumbers())->isNotEmpty()) {
            return $this->failure(trans('admin/purchase-orders/general.vendor_send_needs_part_numbers', [
                'lines' => $missing->pluck('description')->implode(', '),
            ]), $test);
        }

        [$to, $cc] = $this->recipients($order, $actor, $test);

        if ($to === []) {
            return $this->failure(trans('admin/store/general.vendor_send_no_recipients'), $test);
        }

        try {
            $mail = Mail::to($to);
            if ($cc !== []) {
                $mail->cc($cc);
            }
            $mail->send(new RequisitionVendorOrderMail($order->fresh(['supplier']), $test));
        } catch (\Throwable $e) {
            Log::warning('Vendor order email failed for order '.$order->id.': '.$e->getMessage());

            return $this->failure(trans('admin/store/general.vendor_send_failed', ['error' => $e->getMessage()]), $test);
        }

        // A test changes nothing — that is the whole point of it. Only a real
        // send is a send, stamped after the mailer returns rather than before,
        // so a bounced transport does not leave the order looking placed.
        if (! $test) {
            $order->vendor_sent_at = now();
            $order->save();

            // A send that already carries their quote — a re-send after
            // repricing — teaches the catalog the same as a recorded one.
            $this->catalog->apply($order);
        }

        return ['sent' => true, 'error' => null, 'recipients' => $to, 'test' => $test];
    }

    /**
     * Record the vendor's answer, and our answer to it.
     *
     * `sent` records a send that did not go through here, dated. The other
     * three are their loop — four separate facts in all, kept separate because
     * each is a different person's decision on a different day, and one flag
     * would have an order reading as placed while a question is still open.
     * Recording changes reopens the basket — their substitution is the thing
     * that has to land on the lines — and un-confirms whatever we accepted
     * before, because a new answer supersedes it.
     *
     * With `$notifyVendor`, a `confirm` also tells the vendor: the acceptance
     * goes to the same reps the order went to, quoting their number back, and
     * the order is only stamped accepted once that mail has left. A `confirm`
     * without it is a record of a decision made elsewhere — a reply typed by
     * hand, a phone call — and stamps immediately.
     *
     * @param  array<string, mixed>  $fields  validated {@see RESPONSE_FIELDS}, plus
     *                                        `vendor_changes_notes` / `vendor_order_number`
     * @return array{ok: bool, error: ?string, message: ?string, recipients: array<int, string>, stage: string}
     */
    public function respond(Order $order, string $step, array $fields = [], bool $notifyVendor = false): array
    {
        // `sent` is the one step that does not need the order to have gone
        // through here: it records a send made elsewhere — a reply typed by
        // hand, an order placed from a checkout that was not this one — so
        // the rest of the loop can be joined. It emails nobody; the vendor
        // already has the order, which is the premise.
        if ($step === 'sent') {
            foreach (array_merge(self::SEND_FIELDS, ['vendor_sent_at']) as $field) {
                if (array_key_exists($field, $fields) && filled($fields[$field])) {
                    $order->{$field} = $fields[$field];
                }
            }

            $order->vendor_sent_at = $order->vendor_sent_at ?? now();

            if (! $order->save()) {
                return $this->responseFailure($order, implode(' ', $order->getErrors()->all()));
            }

            return [
                'ok' => true,
                'error' => null,
                'message' => trans('admin/purchase-orders/general.vendor_sent_recorded', ['date' => $order->vendor_sent_at->toDateString()]),
                'recipients' => [],
                'stage' => $order->vendorStage(),
            ];
        }

        if ($order->vendor_sent_at === null) {
            return $this->responseFailure($order, trans('admin/purchase-orders/general.vendor_response_not_sent'));
        }

        foreach (self::RESPONSE_FIELDS as $field) {
            if (array_key_exists($field, $fields) && filled($fields[$field])) {
                $order->{$field} = $fields[$field];
            }
        }

        $recipients = [];

        if ($step === 'changes') {
            $order->vendor_changes_at = now();

            if (filled($fields['vendor_changes_notes'] ?? null)) {
                $order->vendor_changes_notes = $fields['vendor_changes_notes'];
            }

            $order->quote_confirmed_at = null;
            $message = 'admin/purchase-orders/general.vendor_changes_recorded';
        } elseif ($step === 'confirm') {
            if ($notifyVendor) {
                // The quote and account go in the acceptance, so whatever was
                // just recorded has to be on the record the mail renders from.
                if ($order->isDirty()) {
                    $order->save();
                }

                [$to, $cc] = $this->recipients($order, null, false);

                if ($to === []) {
                    return $this->responseFailure($order, trans('admin/store/general.vendor_send_no_recipients'));
                }

                try {
                    $mail = Mail::to($to);
                    if ($cc !== []) {
                        $mail->cc($cc);
                    }
                    $mail->send(new PurchaseOrderQuoteAcceptanceMail($order->fresh(['supplier'])));
                } catch (\Throwable $e) {
                    Log::warning('Quote acceptance email failed for order '.$order->id.': '.$e->getMessage());

                    return $this->responseFailure($order, trans('admin/store/general.vendor_send_failed', ['error' => $e->getMessage()]));
                }

                $recipients = $to;
            }

            $order->quote_confirmed_at = $order->quote_confirmed_at ?? now();
            $message = $notifyVendor
                ? 'admin/purchase-orders/general.vendor_quote_confirmed_sent'
                : 'admin/purchase-orders/general.vendor_quote_confirmed';
        } else {
            $order->vendor_order_number = $fields['vendor_order_number'] ?? null;

            // Their number becomes the order's own: the shipment webhook and
            // their invoices arrive under it, and both match on order_number.
            if (filled($order->vendor_order_number)) {
                $order->order_number = $order->vendor_order_number;
            }

            // An order number means they placed it, so the quote we were
            // waiting on is accepted whether or not anyone pressed accept.
            $order->quote_confirmed_at = $order->quote_confirmed_at ?? now();
            $message = 'admin/purchase-orders/general.vendor_order_number_recorded';
        }

        if (! $order->save()) {
            return $this->responseFailure($order, implode(' ', $order->getErrors()->all()));
        }

        // Whatever step carried the quote, the catalog learns its prices now:
        // the quoted unit cost on every line that came from a catalog row,
        // dated, so the next order of the same part starts from a real number.
        $taught = $this->catalog->apply($order);

        return [
            'ok' => true,
            'error' => null,
            'message' => trans($message, ['emails' => implode(', ', $recipients)])
                .($taught->isNotEmpty() ? ' '.trans_choice('admin/purchase-orders/general.catalog_taught', $taught->count(), ['count' => $taught->count()]) : ''),
            'recipients' => $recipients,
            'stage' => $order->vendorStage(),
        ];
    }

    /**
     * Who an order — or its acceptance — reaches. A test goes to whoever
     * pressed the button and nobody else. A real one goes to the supplier's
     * reps, copying the admin lists plus whoever asked for the equipment
     * through the store and anything typed into the form: a store request
     * has a person waiting at the end of it, and this is the thread that
     * tells them it was ordered.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function recipients(Order $order, ?User $actor, bool $test): array
    {
        if ($test) {
            return [[$actor?->email], []];
        }

        $to = EmailTemplate::recipientsFor('procurement.vendor_order', $order->supplier?->order_emails);

        $cc = array_values(array_unique(array_merge(
            EmailTemplate::ccFor('procurement.vendor_order', 'devicesadmins@ecuad.ca,assetsadmins@ecuad.ca'),
            $order->orderCcAddresses()
        )));

        return [array_values(array_filter($to)), $cc];
    }

    /** @return array{sent: bool, error: string, recipients: array<int, string>, test: bool} */
    private function failure(string $error, bool $test): array
    {
        return ['sent' => false, 'error' => $error, 'recipients' => [], 'test' => $test];
    }

    /** @return array{ok: bool, error: string, message: ?string, recipients: array<int, string>, stage: string} */
    private function responseFailure(Order $order, string $error): array
    {
        return ['ok' => false, 'error' => $error, 'message' => null, 'recipients' => [], 'stage' => $order->vendorStage()];
    }
}
