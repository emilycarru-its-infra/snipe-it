<?php

namespace App\Services\Leasing;

use App\Mail\OkayToPayMail;
use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\OrderInvoice;
use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the lessor "OK to pay" for an invoice on a lease account, before the
 * lessor has to ask for it.
 *
 * A vendor bills the lessor, not us, for equipment on a lease schedule, and
 * the lessor holds payment until we confirm the invoice. That confirmation
 * used to wait for the lessor's approval letter, arriving days later and
 * often chased more than once. Every fact it asks us to check — the invoice,
 * its lines and serials, the order it belongs to — is already here the moment
 * the vendor's invoice lands, so the reply can go out on its own.
 *
 * Lifecycle, on order_invoices.okp_status:
 *   queued  the invoice matches its order; sends when the review window ends
 *           (auto mode) or as soon as someone approves it in the queue
 *   held    something does not add up, or someone disputed it in the queue;
 *           never sent unless a person approves it
 *   sent    done, never sent twice
 *
 * Each transition is announced in the Procurement Teams channel, with a
 * reminder the day before a queued invoice sends.
 */
class OkayToPay
{
    public const KEY = 'procurement.okay_to_pay';

    public const QUEUED = 'queued';

    public const HELD = 'held';

    public const SENT = 'sent';

    public const CHANNEL = 'Procurement';

    /** How close to its send time a queued invoice gets its reminder. */
    private const REMINDER_HOURS = 24;

    public function __construct(private readonly TeamsNotifier $teams) {}

    public function mode(): string
    {
        $mode = (string) config('leasing.okp_mode', 'off');

        return in_array($mode, ['review', 'auto'], true) ? $mode : 'off';
    }

    /**
     * Queue or hold a freshly ingested invoice. Called after every ingest of
     * the invoice, so a re-posted webhook re-checks it without re-announcing
     * or moving its send time.
     */
    public function consider(OrderInvoice $invoice, bool $defer = true): void
    {
        if ($this->mode() === 'off' || ! $this->applies($invoice) || $invoice->okp_status === self::SENT) {
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

        return $order
            && ! $invoice->isAdjustment()
            && filled($order->lease_schedule)
            && in_array($order->funding_account, (array) config('leasing.okp_funding_accounts', []), true);
    }

    /**
     * Everything that stops the invoice going out on its own. Empty means it
     * matches its order.
     *
     * @return array<int, string>
     */
    public function mismatches(OrderInvoice $invoice): array
    {
        $invoice->loadMissing(['order', 'items.item']);
        $order = $invoice->order;
        $reasons = [];

        if ($invoice->items->isEmpty()) {
            return [trans('admin/purchase-orders/general.okp_reason_no_lines')];
        }

        if (abs($invoice->variance()) > 1.00) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_variance', [
                'lines' => self::money($invoice->expectedSubtotal()),
                'subtotal' => self::money($invoice->subtotal),
            ]);
        }

        $sum = (float) $invoice->subtotal + (float) $invoice->tax_gst + (float) $invoice->tax_pst + (float) $invoice->shipping;
        if (abs($sum - (float) $invoice->total) > 0.05) {
            $reasons[] = trans('admin/purchase-orders/general.okp_reason_total', [
                'total' => self::money($invoice->total),
                'sum' => self::money($sum),
            ]);
        }

        foreach ($invoice->items as $line) {
            if ($line->item_type !== Asset::class) {
                continue;
            }

            $asset = $line->item;

            if (! $asset instanceof Asset) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_missing_asset', ['description' => $line->description ?: '#'.$line->item_id]);
            } elseif (blank($asset->serial)) {
                $reasons[] = trans('admin/purchase-orders/general.okp_reason_no_serial', ['tag' => $asset->asset_tag]);
            }
        }

        if ($order->quote_total === null) {
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
            // A person's call in the approval queue outranks the checks
            // either way: approving attests the invoice, disputing stops it.
            if ($invoice->approval_status === 'approved') {
                $counts['sent'] += (int) $this->send($invoice);

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

            if (! $invoice->okp_reminded_at && $invoice->okp_send_after->lte(now()->addHours(self::REMINDER_HOURS))) {
                $invoice->forceFill(['okp_reminded_at' => now()])->save();
                $this->announce($invoice, 'reminder', false);
                $counts['reminded']++;
            }
        }

        return $counts;
    }

    /**
     * Send the OK to pay and stamp the invoice approved. Never throws: a
     * failed send leaves the invoice queued for the next pass.
     */
    public function send(OrderInvoice $invoice): bool
    {
        if ($invoice->okp_status === self::SENT) {
            return false;
        }

        $to = $this->recipients();

        if (! $to) {
            return false;
        }

        $cc = array_values(array_diff(EmailTemplate::ccFor(self::KEY, config('leasing.okp_cc')), $to));

        try {
            Mail::to($to)->cc($cc)->send(new OkayToPayMail($invoice));
        } catch (\Throwable $e) {
            Log::error('OK to pay failed for invoice '.$invoice->invoice_number.': '.$e->getMessage());

            return false;
        }

        $invoice->forceFill([
            'okp_status' => self::SENT,
            'okp_sent_at' => now(),
            'okp_sent_to' => implode(', ', $to),
            'approval_status' => 'approved',
            'approved_at' => $invoice->approved_at ?? now(),
        ])->save();

        $this->announce($invoice, 'sent', false);

        return true;
    }

    /** @param  array<int, string>  $reasons */
    private function hold(OrderInvoice $invoice, array $reasons): void
    {
        $invoice->forceFill(['okp_status' => self::HELD, 'okp_reasons' => $reasons])->save();
        $this->announce($invoice, 'held', false);
    }

    /** @return array<int, string> */
    public function recipients(): array
    {
        return EmailTemplate::recipientsFor(self::KEY, config('leasing.okp_to'));
    }

    private function reviewHours(): int
    {
        return max(1, (int) config('leasing.okp_review_hours', 48));
    }

    /**
     * Post the card for a transition: queued, held, reminder or sent.
     */
    public function announce(OrderInvoice $invoice, string $event, bool $defer = true): void
    {
        $card = $this->card($invoice, $event);

        try {
            $defer
                ? $this->teams->sendLater($card, self::CHANNEL, self::KEY)
                : $this->teams->send($card, self::CHANNEL, self::KEY);
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
            ->action(trans('admin/purchase-orders/general.okp_action_order'), route('orders.show', $order->id));
    }

    public static function money($value): string
    {
        return '$'.number_format((float) $value, 2);
    }
}
