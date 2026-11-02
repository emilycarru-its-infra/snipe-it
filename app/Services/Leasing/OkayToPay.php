<?php

namespace App\Services\Leasing;

use App\Mail\BaseMailable;
use App\Mail\OkayToPayMail;
use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\OkayToPayDelivery;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\Supplier;
use App\Services\Settings\Preferences;
use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the lessor "OK to pay" for an invoice on a lease account, before the
 * lessor has to ask for it.
 *
 * A vendor bills the lessor, not us, for equipment on a lease schedule, and
 * the lessor holds payment until we confirm the invoice. That confirmation
 * used to wait for the lessor's approval letter, arriving days later and
 * often chased more than once. Invoice matching is only the financial check:
 * a separately recorded receipt and satisfactory acceptance is mandatory
 * before the payment instruction can leave the system.
 *
 * Lifecycle, on order_invoices.okp_status:
 *   queued  the invoice matches its order; sends on the next scheduled pass,
 *           or when the review window ends if one is set (auto mode), or as
 *           soon as someone approves it in the queue
 *   held    something does not add up, or someone disputed it in the queue;
 *           sent only after the facts and current acceptance satisfy every gate
 *   sent    done, never sent twice
 *
 * A held invoice is always announced in Teams, on the channel set for it.
 * With a review window set, so are queued, a reminder in the last quarter of
 * the window, and sent; with none, a match goes out without a card.
 */
class OkayToPay
{
    public const KEY = 'procurement.okay_to_pay';

    public const QUEUED = 'queued';

    public const HELD = 'held';

    public const SENT = 'sent';

    public const SENDING = 'sending';

    public const UNCERTAIN = 'uncertain';

    public function __construct(private readonly TeamsNotifier $teams) {}

    /**
     * One of this email's settings: Settings → Emails when an admin has set
     * it, otherwise the environment default in config/leasing.php.
     */
    public static function setting(string $name): mixed
    {
        return EmailTemplate::optionFor(self::KEY, $name, config('leasing.okp_'.$name));
    }

    /**
     * The lessor this is for. Only its invoices are considered, and nobody
     * outside the university but this lessor may receive the email.
     */
    public static function lessor(): ?Supplier
    {
        $name = trim((string) self::setting('lessor'));

        return $name === '' ? null : Supplier::where('name', $name)->first();
    }

    public function mode(): string
    {
        $mode = (string) self::setting('mode');

        return in_array($mode, ['review', 'auto'], true) ? $mode : 'off';
    }

    /**
     * Queue or hold a freshly ingested invoice. Called after every ingest of
     * the invoice, so a re-posted webhook re-checks it without re-announcing
     * or moving its send time.
     */
    public function consider(OrderInvoice $invoice, bool $defer = true): void
    {
        OkayToPayHistory::protect($invoice);
        if ($this->mode() === 'off' || ! $this->applies($invoice) || $invoice->okp_status === self::SENT || OkayToPayDelivery::where('invoice_id', $invoice->id)->exists()) {
            return;
        }

        // Settled before this existed, or by hand: someone already attested
        // it, so re-sending the vendor's invoice must not re-send ours.
        if ($invoice->okp_status === null && $invoice->approval_status === 'approved') {
            return;
        }

        if (OkayToPayHistory::blocked($invoice)) {
            $this->hold($invoice, [trans('admin/purchase-orders/general.okp_reason_historical_settlement')]);

            return;
        }

        if (! $this->recipients()) {
            Log::warning('OK to pay: no recipients configured, invoice '.$invoice->invoice_number.' not queued.');

            return;
        }

        $reasons = $this->mismatches($invoice);
        $before = [$invoice->okp_status, $invoice->okp_reasons];

        if ($reasons) {
            $invoice->forceFill(['okp_status' => self::HELD, 'okp_reasons' => $reasons, 'okp_send_after' => null]);
            if ($invoice->approval_status === 'approved') {
                $invoice->forceFill(['approval_status' => 'pending', 'approved_at' => null, 'approved_by' => null]);
            }
        } else {
            $invoice->forceFill([
                'okp_status' => self::QUEUED,
                'okp_reasons' => null,
                'okp_send_after' => $invoice->okp_send_after ?? now()->addHours($this->reviewHours()),
            ]);
        }

        $invoice->save();

        if ($before !== [$invoice->okp_status, $invoice->okp_reasons]) {
            $this->announce($invoice, $invoice->okp_status === self::QUEUED ? 'queued' : 'held', $defer);
        }
    }

    /**
     * Whether the lessor pays this invoice: a regular invoice on an order
     * funded from a lease account and placed on a schedule.
     */
    public function applies(OrderInvoice $invoice): bool
    {
        $order = $invoice->order;
        $from = (string) self::setting('invoices_from');

        if ($from === '' || ! $invoice->invoice_date || $invoice->invoice_date->lt(Carbon::parse($from)->startOfDay())) {
            return false;
        }

        // Another lessor's lease is not ours to sign off to this one. A
        // schedule whose lessor cannot be told is still considered, and held
        // by mismatches() so a person decides.
        $lessor = self::lessor();
        if (! $lessor || ! $order) {
            return false;
        }
        $owner = app(LessorGuard::class)->lessorForContract($order->lease_schedule);
        if ($owner && $owner->id !== $lessor->id) {
            return false;
        }

        return ! $invoice->isAdjustment()
            && filled($order->lease_schedule)
            && in_array($order->funding_account, Preferences::get('leasing.okp_funding_accounts'), true);
    }

    /**
     * Everything that stops the invoice going out on its own. Empty means it
     * matches its order.
     *
     * @return array<int, string>
     */
    public function mismatches(OrderInvoice $invoice, bool $requireAcceptance = true): array
    {
        $invoice->refresh()->load(['order', 'items.item']);
        $reasons = $requireAcceptance ? app(OkayToPayAcceptance::class)->reasons($invoice) : [];
        $order = $invoice->order;
        if (OkayToPayHistory::blocked($invoice)) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_historical_settlement');
        }
        if ($invoice->approval_status === 'disputed') {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_disputed');
        }
        if (! $order || $order->status === 'cancelled') {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_order_cancelled');
        }

        if ($invoice->items->isEmpty()) {
            return array_merge($reasons, [trans('admin/purchase-orders/general.okp_reason_no_lines')]);
        }

        if (! $order) {
            return $reasons;
        }

        $lessor = self::lessor();
        $owner = app(LessorGuard::class)->lessorForContract($order->lease_schedule);
        if (! $lessor || ! $owner || $owner->id !== $lessor->id) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_lessor_unknown', ['schedule' => $order->lease_schedule]);
        }

        if (! $order->supplier_id || blank($invoice->invoice_number)) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_vendor_missing');
        } elseif (DB::table('order_invoices')->join('orders', 'orders.id', '=', 'order_invoices.order_id')
            ->where('orders.supplier_id', $order->supplier_id)
            ->where('order_invoices.id', '!=', $invoice->id)
            ->whereRaw('LOWER(TRIM(order_invoices.invoice_number)) = ?', [mb_strtolower(trim($invoice->invoice_number))])->exists()) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_duplicate_invoice');
        }
        foreach (['subtotal', 'tax_gst', 'tax_pst', 'shipping', 'total'] as $field) {
            if ($invoice->$field === null || (float) $invoice->$field < 0) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_amount_missing');
            }
        }
        if ((float) $invoice->total <= 0) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_amount_missing');
        }

        if (abs($invoice->variance()) > 1.00) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_variance', [
                'lines' => self::money($invoice->expectedSubtotal()),
                'subtotal' => self::money($invoice->subtotal),
            ]);
        }

        $sum = (float) $invoice->subtotal + (float) $invoice->tax_gst + (float) $invoice->tax_pst + (float) $invoice->shipping;
        if (abs($sum - (float) $invoice->total) > (float) Preferences::get('procurement.money_match_tolerance')) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_total', [
                'total' => self::money($invoice->total),
                'sum' => self::money($sum),
            ]);
        }

        foreach ($invoice->items as $line) {
            if ($line->quantity < 1 || $line->unit_cost === null || $line->warranty_cost === null || (float) $line->unit_cost < 0 || (float) $line->warranty_cost < 0) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_amount_missing');
            }
            if ($line->item_type !== Asset::class) {
                continue;
            }

            $asset = $line->item;

            if (! $asset instanceof Asset) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_missing_asset', ['description' => $line->description ?: '#'.$line->item_id]);
            } elseif ($lessor && $asset->lessor_id && (int) $asset->lessor_id !== (int) $lessor->id) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_asset_lessor', ['tag' => $asset->asset_tag]);
            } elseif ($asset->status?->archived || $asset->decommission_date) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_asset_withdrawn');
            } elseif (blank($asset->serial)) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_no_serial', ['tag' => $asset->asset_tag]);
            }
        }

        if ($order->quote_total === null || ! $order->quote_confirmed_at) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_no_quote', ['order' => $order->order_number]);
        } else {
            // Cumulative, because the order is billed across several
            // invoices: each one is fine only while the order as a whole
            // stays within what we accepted.
            $invoiced = (float) $order->invoices()
                ->whereNotIn('invoice_type', OrderInvoice::ADJUSTMENT_TYPES)
                ->sum('subtotal');

            if ($invoiced > (float) $order->quote_total + 1.00) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_over_quote', [
                    'invoiced' => self::money($invoiced),
                    'quote' => self::money($order->quote_total),
                    'order' => $order->order_number,
                ]);
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * The scheduled pass: send what is due or approved, hold what someone
     * disputed, and remind the channel the day before a send.
     *
     * @return array{sent: int, held: int, reminded: int}
     */
    public function dispatchDue(): array
    {
        $counts = ['sent' => 0, 'held' => 0, 'reminded' => 0];

        if ($this->mode() === 'off') {
            return $counts;
        }

        $invoices = OrderInvoice::query()
            ->whereIn('okp_status', [self::QUEUED, self::HELD])
            ->with(['order', 'items.item'])
            ->get();

        foreach ($invoices as $invoice) {
            // An approval can release the wait, but never bypass receipt,
            // acceptance, ownership, dispute or monetary safety checks.
            if ($invoice->approval_status === 'approved') {
                // Failed safety checks return to pending. A retained delivery
                // reservation remains blocked regardless of later approvals.
                if (! $this->send($invoice)) {
                    $invoice->forceFill(['approval_status' => 'pending', 'approved_at' => null, 'approved_by' => null])->save();
                } else {
                    $counts['sent']++;
                }

                continue;
            }

            if ($invoice->okp_status !== self::QUEUED) {
                continue;
            }

            if ($invoice->approval_status === 'disputed') {
                $this->hold($invoice, [trans('admin/purchase-orders/general.okp_reason_disputed')]);
                $counts['held']++;

                continue;
            }

            if ($this->mode() !== 'auto' || ! $invoice->okp_send_after) {
                continue;
            }

            if ($invoice->okp_send_after->isPast()) {
                // Re-checked at send time: a later invoice may have pushed
                // the order over its quote since this one was queued.
                $reasons = $this->mismatches($invoice);

                if ($reasons) {
                    $this->hold($invoice, $reasons);
                    $counts['held']++;
                } else {
                    $counts['sent'] += (int) $this->send($invoice);
                }

                continue;
            }

            if ($this->reviewed() && ! $invoice->okp_reminded_at && $invoice->okp_send_after->lte(now()->addHours($this->reminderHours()))) {
                $invoice->forceFill(['okp_reminded_at' => now()])->save();
                $this->announce($invoice, 'reminder', false);
                $counts['reminded']++;
            }
        }

        return $counts;
    }

    /**
     * Reserve once before touching the transport. A crash or uncertain SMTP
     * response keeps the reservation; neither a webhook nor approval retries it.
     */
    public function send(OrderInvoice $invoice): bool
    {
        $reservation = DB::transaction(function () use ($invoice) {
            Order::whereKey($invoice->order_id)->lockForUpdate()->first();
            $current = OrderInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($current->okp_status === self::SENT || $current->okp_sent_at
                || ($current->okp_status === null && $current->approval_status === 'approved') || OkayToPayDelivery::where('invoice_id', $current->id)->exists()) {
                return null;
            }
            Preferences::flush();
            app(LessorGuard::class)->forgetPrefixes();
            app(OkayToPayAcceptance::class)->lockFacts($current);
            if (! in_array($current->okp_status, [self::QUEUED, self::HELD], true) || $this->mode() === 'off' || ! $this->applies($current)) {
                $this->hold($current, [trans('admin/purchase-orders/general.okp_reason_ineligible')]);

                return null;
            }
            if (! $this->dueForSending($current)) {
                return null;
            }
            $reasons = $this->mismatches($current);
            if ($reasons) {
                $this->hold($current, $reasons);

                return null;
            }
            $to = $this->recipients();
            $cc = array_values(array_diff(EmailTemplate::ccFor(self::KEY, config('leasing.okp_cc')), $to));
            $lessor = self::lessor();
            $foreign = $lessor ? app(LessorGuard::class)->foreignRecipients($lessor, array_merge($to, $cc)) : array_merge($to, $cc);
            if (! $to || $foreign) {
                $this->hold($current, [! $to
                    ? trans('admin/purchase-orders/general.okp_reason_no_recipient')
                    : trans('admin/purchase-orders/general.okp_reason_foreign_recipient', ['addresses' => implode(', ', $foreign)])]);

                return null;
            }
            $evidence = app(OkayToPayAcceptance::class)->latest($current);
            BaseMailable::flushSubjectCache();
            $mail = new OkayToPayMail($current);
            $payload = json_encode($mail->freeze(), JSON_THROW_ON_ERROR);
            $delivery = OkayToPayDelivery::create([
                'invoice_id' => $current->id,
                'invoice_key' => hash('sha256', $current->order->supplier_id."\n".mb_strtolower(trim($current->invoice_number))),
                'acceptance_id' => $evidence->id,
                'fingerprint' => $evidence->fingerprint,
                'status' => self::SENDING,
                'recipients' => implode(', ', $to),
                'cc' => implode(', ', $cc),
                'payload' => $payload,
                'payload_digest' => hash('sha256', $payload),
                'routing_digest' => $this->routingDigest(),
                'claimed_at' => now(),
            ]);
            $current->forceFill(['okp_status' => self::SENDING, 'okp_reasons' => null])->save();

            // Materialize the mailable from the same checked facts before releasing
            // the reservation transaction; later edits cannot change its contents.
            return [$current, $delivery, $to, $cc, $mail];
        });
        if (! $reservation) {
            return false;
        }
        [$current, $delivery, $to, $cc, $mail] = $reservation;
        $sent = DB::transaction(function () use ($current, $delivery, $to, $cc, $mail) {
            Order::whereKey($current->order_id)->lockForUpdate()->first();
            $checked = OrderInvoice::whereKey($current->id)->lockForUpdate()->firstOrFail();
            $acceptance = app(OkayToPayAcceptance::class);
            $acceptance->lockFacts($checked);
            Preferences::flush();
            app(LessorGuard::class)->forgetPrefixes();
            $reasons = $this->mismatches($checked);
            $currentTo = $this->recipients();
            $currentCc = array_values(array_diff(EmailTemplate::ccFor(self::KEY, config('leasing.okp_cc')), $currentTo));
            if (! $this->dueForSending($checked, reserved: true) || $this->mode() === 'off' || ! $this->applies($checked)
                || ! hash_equals($delivery->fingerprint, $acceptance->fingerprint($checked))
                || ! hash_equals($delivery->routing_digest, $this->routingDigest())
                || $to !== $currentTo || $cc !== $currentCc) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_acceptance_stale');
            }
            $lessor = self::lessor();
            $foreign = $lessor ? app(LessorGuard::class)->foreignRecipients($lessor, array_merge($currentTo, $currentCc)) : array_merge($currentTo, $currentCc);
            if ($foreign) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_foreign_recipient', ['addresses' => implode(', ', $foreign)]);
            }
            if ($reasons) {
                $delivery->forceFill(['status' => 'blocked', 'error' => implode(' ', $reasons)])->save();
                $this->hold($checked, $reasons);

                return false;
            }
            try {
                Mail::to($to)->cc($cc)->send($mail);
            } catch (\Throwable $e) {
                Log::error('OK to pay transport outcome uncertain for invoice '.$checked->invoice_number.': '.$e->getMessage());
                $delivery->forceFill(['status' => self::UNCERTAIN, 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
                $checked->forceFill([
                    'okp_status' => self::UNCERTAIN,
                    'okp_reasons' => [trans('admin/purchase-orders/general.okp_reason_delivery_reserved')],
                    'approval_status' => 'pending',
                    'approved_at' => null,
                    'approved_by' => null,
                ])->save();
                $this->announce($checked, 'held', false);

                return false;
            }
            $delivery->forceFill(['status' => self::SENT, 'completed_at' => now()])->save();
            $checked->forceFill([
                'okp_status' => self::SENT,
                'okp_sent_at' => now(),
                'okp_sent_to' => implode(', ', $to),
                'approval_status' => 'approved',
                'approved_at' => $checked->approved_at ?? now(),
            ])->save();

            return true;
        });
        $invoice->refresh();
        if ($sent) {
            $this->announce($invoice, 'sent', false);
        }

        return $sent;
    }

    private function dueForSending(OrderInvoice $invoice, bool $reserved = false): bool
    {
        return $invoice->approval_status === 'approved'
            || ($this->mode() === 'auto'
                && $invoice->okp_status === ($reserved ? self::SENDING : self::QUEUED)
                && $invoice->okp_send_after?->isPast());
    }

    private function routingDigest(): string
    {
        $template = EmailTemplate::forKey(self::KEY);
        $lessor = self::lessor();

        return hash('sha256', json_encode([
            $this->recipients(), EmailTemplate::ccFor(self::KEY, config('leasing.okp_cc')),
            $this->mode(), self::setting('invoices_from'), self::setting('from_address'), self::setting('from_name'),
            $template?->subject, $template?->body,
            $lessor?->id, $lessor?->email, $lessor?->lease_emails, $lessor?->contract_prefixes,
            app(LessorGuard::class)->internalDomains(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param  array<int, string>  $reasons */
    private function hold(OrderInvoice $invoice, array $reasons): void
    {
        $state = ['okp_status' => self::HELD, 'okp_reasons' => $reasons];
        if ($invoice->approval_status === 'approved') {
            $state += ['approval_status' => 'pending', 'approved_at' => null, 'approved_by' => null];
        }
        $invoice->forceFill($state)->save();
        $this->announce($invoice, 'held', false);
    }

    /** @return array<int, string> */
    public function recipients(): array
    {
        return EmailTemplate::recipientsFor(self::KEY, config('leasing.okp_to'));
    }

    private function reviewHours(): int
    {
        return max(0, (int) self::setting('review_hours'));
    }

    /**
     * With no review window an invoice that matches simply goes, and the only
     * thing worth a card is one that did not: queued, reminder and sent cards
     * belong to the reviewed flow.
     */
    private function reviewed(): bool
    {
        return $this->reviewHours() > 0;
    }

    /**
     * How close to its send time a queued invoice gets its reminder: the last
     * quarter of the window, so a one-day window nudges six hours out rather
     * than repeating the queued card the moment it is posted.
     */
    private function reminderHours(): int
    {
        return max(1, min(24, intdiv(max(1, $this->reviewHours()), 4)));
    }

    /** The Teams channel the cards go to. */
    public function channel(): string
    {
        return (string) (EmailTemplate::optionFor(self::KEY, 'teams_channel') ?: Preferences::get('leasing.okp_teams_channel'));
    }

    /**
     * Post the card for a transition: queued, held, reminder or sent.
     */
    public function announce(OrderInvoice $invoice, string $event, bool $defer = true): void
    {
        if ($event !== 'held' && ! $this->reviewed()) {
            return;
        }

        $card = $this->card($invoice, $event);

        try {
            $defer
                ? $this->teams->sendLater($card, $this->channel(), self::KEY)
                : $this->teams->send($card, $this->channel(), self::KEY);
        } catch (\Throwable $e) {
            Log::warning('OK to pay card failed for invoice '.$invoice->invoice_number.': '.$e->getMessage());
        }
    }

    public function card(OrderInvoice $invoice, string $event): TeamsCard
    {
        $invoice->loadMissing(['order.supplier', 'items.item']);
        $order = $invoice->order;
        $when = $invoice->okp_send_after ? Carbon::parse($invoice->okp_send_after)->timezone(config('app.timezone')) : null;

        [$title, $accent] = match ($event) {
            'queued' => [trans('admin/purchase-orders/general.okp_card_queued'), 'accent'],
            'reminder' => [trans('admin/purchase-orders/general.okp_card_reminder'), 'warning'],
            'held' => [trans('admin/purchase-orders/general.okp_card_held'), 'attention'],
            default => [trans('admin/purchase-orders/general.okp_card_sent'), 'good'],
        };

        $serials = $invoice->items
            ->map(fn ($line) => $line->item instanceof Asset ? $line->item->serial : null)
            ->filter()
            ->unique()
            ->values();

        $card = TeamsCard::make($title.' — '.$invoice->invoice_number)
            ->accent($accent)
            ->subtitle(trim(($order->supplier->name ?? '').' · '.$order->lease_schedule, ' ·'))
            ->facts([
                trans('admin/purchase-orders/general.okp_fact_order') => $order->order_number,
                trans('admin/purchase-orders/general.okp_fact_invoice_date') => optional($invoice->invoice_date)->toDateString(),
                trans('admin/purchase-orders/general.okp_fact_total') => self::money($invoice->total).' (GST '.self::money($invoice->tax_gst).')',
                trans('admin/purchase-orders/general.okp_fact_serials') => $serials->isEmpty() ? null : $serials->implode(', '),
                trans('admin/purchase-orders/general.okp_fact_sends') => in_array($event, ['queued', 'reminder'], true) && $when
                    ? ($this->mode() === 'auto' ? $when->format('D M j, g:i A') : trans('admin/purchase-orders/general.okp_fact_sends_on_approval'))
                    : null,
                trans('admin/purchase-orders/general.okp_fact_sent_to') => $event === 'sent' ? $invoice->okp_sent_to : null,
            ]);

        if ($event === 'held' && $invoice->okp_reasons) {
            $card->note(implode("\n", array_map(fn ($r) => '• '.$r, (array) $invoice->okp_reasons)));
        } elseif (in_array($event, ['queued', 'reminder'], true)) {
            $card->note(trans('admin/purchase-orders/general.okp_card_review_note'));
        }

        return $card
            ->action(trans('admin/purchase-orders/general.okp_action_queue'), route('reports.procurement.invoice-approval', ['status' => 'all']))
            ->action(trans('admin/purchase-orders/general.okp_action_order'), route('orders.show', $order));
    }

    public static function money($value): string
    {
        return '$'.number_format((float) $value, 2);
    }
}
