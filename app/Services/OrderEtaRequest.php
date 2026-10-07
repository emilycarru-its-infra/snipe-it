<?php

namespace App\Services;

use App\Enums\ActionType;
use App\Mail\OrderEtaRequestMail;
use App\Models\Actionlog;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Leasing\LessorGuard;
use App\Services\Settings\Preferences;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Asking the vendor where an order is.
 *
 * The most repeated email in procurement: "what is the ETA for order X?",
 * typed by hand to the reps with the team copied, every time a shipment runs
 * late. Here it is one button on the order page and one call on the API, and
 * both go through this class so the two cannot disagree about who it reaches.
 *
 * It lists only what is still to arrive — a vendor asked about a whole order
 * answers about the whole order, including the half already delivered — and
 * quotes their number before ours, because their desk searches on their own.
 *
 * Every real send is stamped on the order (when, and how many times), and
 * logged on its history, so the second chase reads as a second chase.
 */
class OrderEtaRequest
{
    public const KEY = 'procurement.eta_request';

    /**
     * Who it would go to and what it would say, without sending. The order
     * page's dialog opens on this, so what the sender edits is what the
     * vendor would otherwise have received.
     *
     * @param  array<string, mixed>  $fields  `to`, `cc_users`, `subject`, `note`
     * @return array{to: array<int, string>, cc: array<int, string>, subject: string, reference: string, lines: array<int, array<string, mixed>>, error: ?string}
     */
    public function preview(Order $order, ?User $actor, array $fields = []): array
    {
        $order->loadMissing('supplier', 'purchaseOrder', 'items');

        [$to, $cc] = $this->recipients($order, $actor, $fields, false);

        return [
            'to' => $to,
            'cc' => $cc,
            'subject' => $this->subject($order, $fields),
            'reference' => $order->etaReference(),
            'lines' => $order->outstandingLines()->map(fn ($line) => [
                'description' => $line->description,
                'quantity' => (int) $line->quantity,
                'mfr_part_number' => $line->mfr_part_number,
                'vendor_sku' => $line->vendor_sku,
            ])->all(),
            'error' => $this->refusal($order, $to, $cc),
        ];
    }

    /**
     * Send it. A test goes to the sender alone and changes nothing on the
     * order; a real send goes to the reps, copying the team, and is stamped
     * only once the mailer has returned.
     *
     * @param  array<string, mixed>  $fields  `to` (addresses), `cc_users` (user ids),
     *                                        `subject`, `note`
     * @return array{sent: bool, error: ?string, to: array<int, string>, cc: array<int, string>, test: bool}
     */
    public function send(Order $order, User $actor, array $fields = [], bool $test = false): array
    {
        $order->loadMissing('supplier', 'purchaseOrder', 'items');

        [$to, $cc] = $this->recipients($order, $actor, $fields, $test);

        if ($error = $this->refusal($order, $to, $cc)) {
            return $this->failure($error, $test);
        }

        $subject = $this->subject($order, $fields);
        $note = trim((string) ($fields['note'] ?? ''));

        try {
            $mail = Mail::to($to);
            if ($cc !== []) {
                $mail->cc($cc);
            }
            $mail->send(new OrderEtaRequestMail($order, $actor, $subject, $note, $test));
        } catch (\Throwable $e) {
            Log::warning('ETA request email failed for order '.$order->id.': '.$e->getMessage());

            return $this->failure(trans('admin/orders/general.eta_request_failed', ['error' => $e->getMessage()]), $test);
        }

        if (! $test) {
            $order->eta_requested_at = now();
            $order->eta_request_count = (int) $order->eta_request_count + 1;
            $order->save();

            $log = new Actionlog;
            $log->item_type = Order::class;
            $log->item_id = $order->id;
            $log->setAttribute('created_by', $actor->id);
            $log->target_type = Supplier::class;
            $log->target_id = $order->supplier_id;
            $log->note = $subject.' — '.implode(', ', array_merge($to, $cc));
            $log->logaction(ActionType::EtaRequested->value);
        }

        return ['sent' => true, 'error' => null, 'to' => $to, 'cc' => $cc, 'test' => $test];
    }

    /** The subject as sent: the sender's, else the saved override, else the default. */
    private function subject(Order $order, array $fields): string
    {
        if (filled($fields['subject'] ?? null)) {
            return trim((string) $fields['subject']);
        }

        return OrderEtaRequestMail::defaultSubject($order);
    }

    /**
     * To: whoever the caller named, else Settings → Emails' list for this
     * email, else the supplier's order list — the reps the order went to.
     * CC: the team (Settings → Emails, else the device team contact list),
     * the people picked on the send, and the sender, so the reply lands in
     * their inbox as well as the shared one.
     *
     * Deliberately not the order's own CC list: that carries the store
     * requesters, and a faculty member does not need to read us chasing the
     * reseller.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function recipients(Order $order, ?User $actor, array $fields, bool $test): array
    {
        if ($test) {
            return [array_values(array_filter([$actor?->email])), []];
        }

        $named = collect((array) ($fields['to'] ?? []))
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter()
            ->values()
            ->all();

        $to = $named !== [] ? $named : EmailTemplate::recipientsFor(self::KEY, $order->supplier?->order_emails);

        $picked = User::whereIn('id', (array) ($fields['cc_users'] ?? []))->pluck('email')->all();

        $cc = collect(EmailTemplate::ccFor(self::KEY, Preferences::csv('contacts.device_team')))
            ->merge($picked)
            ->push($actor?->email)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->reject(fn ($email) => in_array($email, array_map('strtolower', $to), true))
            ->values()
            ->all();

        return [array_values(array_unique(array_map('strtolower', array_filter($to)))), $cc];
    }

    /**
     * Why this cannot go, or null.
     *
     * Lessor isolation holds here too: an ETA request is a question for the
     * reseller, and an address belonging to a lessor other than this order's
     * own supplier would tell one lessor about an order on the other's lease.
     * Nothing names a lessor — the domains come from the Supplier records that
     * declare contract prefixes, as everywhere else LessorGuard decides.
     *
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     */
    private function refusal(Order $order, array $to, array $cc): ?string
    {
        if ($order->status === 'cancelled') {
            return trans('admin/orders/general.eta_request_cancelled');
        }

        if ($order->outstandingLines()->isEmpty()) {
            return trans('admin/orders/general.eta_request_nothing_outstanding');
        }

        if ($to === []) {
            return trans('admin/orders/general.eta_request_no_recipients');
        }

        $guard = app(LessorGuard::class);
        $internal = $guard->internalDomains();
        $foreign = collect($guard->declaredPrefixes())
            ->pluck('supplier')
            ->unique('id')
            ->reject(fn (Supplier $lessor) => (int) $lessor->id === (int) $order->supplier_id)
            ->flatMap(fn (Supplier $lessor) => $guard->domains($lessor))
            ->reject(fn ($domain) => in_array($domain, $internal, true))
            ->unique()
            ->all();

        // Every address must be a bare, valid mailbox before its domain is
        // read: a display name or stray bracket ("Rep <x@lessor.example>")
        // would otherwise yield a domain that matches nothing and slip past.
        $invalid = collect(array_merge($to, $cc))
            ->reject(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->all();

        if ($invalid !== []) {
            return trans('admin/orders/general.eta_request_invalid_recipient', ['emails' => implode(', ', $invalid)]);
        }

        // A subdomain of a lessor's domain is still that lessor.
        $crossing = collect(array_merge($to, $cc))
            ->filter(function ($email) use ($foreign) {
                $domain = strtolower(substr((string) strrchr($email, '@'), 1));

                return collect($foreign)->contains(fn ($lessorDomain) => $domain === $lessorDomain
                    || str_ends_with($domain, '.'.$lessorDomain));
            })
            ->values()
            ->all();

        if ($crossing !== []) {
            return trans('admin/orders/general.eta_request_lessor_recipient', ['emails' => implode(', ', $crossing)]);
        }

        return null;
    }

    /** @return array{sent: bool, error: string, to: array<int, string>, cc: array<int, string>, test: bool} */
    private function failure(string $error, bool $test): array
    {
        return ['sent' => false, 'error' => $error, 'to' => [], 'cc' => [], 'test' => $test];
    }
}
