<?php

namespace App\Console\Commands;

use App\Mail\EmailDelivery;
use App\Models\ProcurementDigestThread;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Services\Teams\ReportCard;
use App\Services\Teams\TeamsNotifier;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The daily list of everything in procurement that is waiting on one of us.
 *
 * The event cards fire once, when an order is placed or a quote lands, and a
 * card that scrolls past in a busy channel is a card nobody acted on. This
 * repeats every open item every morning until somebody moves it, so an order
 * waiting for review or a quote nobody signed off cannot go quiet.
 *
 * Only the steps where the next move is ours are listed. An order request
 * sitting with the vendor with no quote back yet is the vendor's to answer,
 * and listing it would bury the rows that are actually ours.
 *
 * Safe to run by hand:
 *   php artisan snipeit:procurement-actions --dry-run
 */
class SendProcurementActionsReport extends Command
{
    public const KEY = 'report.procurement_actions';

    protected $signature = 'snipeit:procurement-actions
                            {--dry-run : List what would be posted without posting it}
                            {--updates-only : Reply to existing digests without posting a new digest}';

    protected $description = 'Post the store orders and vendor quotes waiting on procurement to Teams.';

    public function handle(): int
    {
        $rows = $this->rows();

        if ($this->option('dry-run')) {
            $this->table($this->columns(), $rows->map(fn (array $row) => $this->cells($row))->all());

            return self::SUCCESS;
        }

        // There is no email form of this report: it is a channel digest. If
        // Teams cannot take it, say so loudly rather than pretend it went out.
        if (! EmailDelivery::shouldPostToTeams(self::KEY)) {
            Log::warning('Procurement actions digest not posted: Teams delivery is off or unreachable.', ['count' => $rows->count()]);
            $this->warn('Teams delivery is off or unreachable — nothing posted.');

            return self::SUCCESS;
        }

        $this->updateThreads($rows);

        if ($this->option('updates-only') || $rows->isEmpty()) {
            return self::SUCCESS;
        }

        $card = ReportCard::make(
            trans('admin/store/general.actions_report_title'),
            'attention',
            $this->columns(),
            $rows,
            fn (array $row) => $this->cells($row),
            $rows->countBy('action')->all(),
            route('procurement.approvals'),
        );
        $thread = ProcurementDigestThread::create([
            'channel' => EmailDelivery::channelFor(self::KEY),
            'pending' => $rows->mapWithKeys(fn ($row) => [$row['identity'] => [
                'reference' => $row['reference'], 'action' => $row['action'],
            ]])->all(),
            'cards' => $card->cards(),
        ]);
        $this->updateThread($thread->id, $rows->keyBy('identity'));

        $this->info('Queued '.$rows->count().' item(s).');

        return self::SUCCESS;
    }

    /** Keep every digest that mentioned an action informed, including after the queue empties. */
    private function updateThreads(Collection $rows): void
    {
        $current = $rows->keyBy('identity');
        ProcurementDigestThread::whereNull('completed_at')->select('id')->chunkById(100, function ($threads) use ($current) {
            foreach ($threads as $thread) {
                $this->updateThread($thread->id, $current);
            }
        });
    }

    private function updateThread(string $id, Collection $current): void
    {
        // Both scheduled commands can run together. Serialize each thread and
        // checkpoint every accepted part/reply before another worker can send it.
        DB::transaction(function () use ($id, $current) {
            $thread = ProcurementDigestThread::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($thread->completed_at) {
                return;
            }
            $notifier = app(TeamsNotifier::class);
            $routing = ['thread_repo' => 'StoreProcurement', 'thread_key' => $thread->id];
            $cards = $thread->cards;
            while ($thread->next_part < count($cards)) {
                if (! $notifier->sendContent($cards[$thread->next_part], $thread->channel, self::KEY,
                    $routing + ['is_root' => $thread->next_part === 0])) {
                    return;
                }
                $thread->increment('next_part');
            }

            $pending = $thread->pending;
            foreach ($pending as $identity => $previous) {
                if ($current->has($identity)) {
                    continue;
                }
                [$type, $orderId] = explode(':', $identity);
                $order = $type === 'store' ? StoreOrder::find($orderId) : PurchaseOrder::find($orderId);
                $status = $order === null
                    ? trans('admin/store/general.actions_unavailable')
                    : ($type === 'store'
                        ? trans('admin/store/general.order_status_'.$order->displayStatus())
                        : ($order->quote_confirmed_at || filled($order->vendor_order_number)
                            ? trans('admin/store/general.order_status_ordered')
                            : trans('admin/purchase-orders/general.status_'.$order->status)));
                $reply = ReportCard::make(
                    trans('admin/store/general.actions_update_title'), 'good',
                    [trans('admin/store/general.actions_col_reference'),
                        trans('admin/store/general.actions_previous'), trans('general.status')],
                    [[$previous['reference'], $previous['action'], $status]], fn ($row) => $row,
                    url: route('procurement.approvals'),
                );
                foreach ($reply->cards() as $content) {
                    if (! $notifier->sendContent($content, $thread->channel, self::KEY, $routing)) {
                        return;
                    }
                }
                unset($pending[$identity]);
                $thread->pending = $pending;
                $thread->save();
            }
            if ($pending === []) {
                $thread->completed_at = now();
                // Keep the thread receipt, not a permanent copy of the digest.
                $thread->cards = [];
                $thread->save();
            }
        });
    }

    /** @return array<int, string> */
    private function columns(): array
    {
        return [
            trans('admin/store/general.actions_col_action'),
            trans('admin/store/general.actions_col_reference'),
            trans('admin/store/general.actions_col_for'),
            trans('admin/store/general.actions_col_gl'),
            trans('admin/store/general.actions_col_total'),
            trans('admin/store/general.actions_col_waiting'),
        ];
    }

    /**
     * @param  array{identity: string, action: string, reference: string, for: ?string, gl: ?string, total: ?float, since: ?CarbonInterface}  $row
     * @return array<int, mixed>
     */
    private function cells(array $row): array
    {
        return [
            $row['action'],
            $row['reference'],
            $row['for'],
            $row['gl'],
            $row['total'] ? '$'.number_format($row['total'], 2) : null,
            $row['since'] ? $row['since']->diffForHumans(['parts' => 1]) : null,
        ];
    }

    /**
     * Oldest first within each step, so what has waited longest is read first.
     *
     * @return Collection<int, array{identity: string, action: string, reference: string, for: ?string, gl: ?string, total: ?float, since: ?CarbonInterface}>
     */
    private function rows(): Collection
    {
        return $this->storeRows()->concat($this->purchaseOrderRows())->values();
    }

    private function storeRows(): Collection
    {
        $orders = StoreOrder::with('items', 'user', 'requisition.purchaseOrder')
            ->whereIn('status', ['pending', 'approved', 'ordered'])
            ->orderBy('created_at')
            ->get();

        $row = fn (StoreOrder $order, string $action, ?CarbonInterface $since) => [
            'identity' => 'store:'.$order->id.':'.match ($order->status) {
                'pending' => 'review', 'approved' => 'send', default => 'quote',
            },
            'action' => $action,
            'reference' => $order->reference(),
            'for' => $order->user?->getAttribute('display_name') ?: $order->user?->username,
            // The reviewer's first question is what it is charged to; a GL
            // typed in at order time answers it without opening the order.
            'gl' => $order->gl_code ?: null,
            'total' => $order->quote_total !== null ? (float) $order->quote_total : $order->total(),
            'since' => $since,
        ];

        $pending = $orders->where('status', 'pending')
            ->map(fn (StoreOrder $o) => $row($o, trans('admin/store/general.actions_review'), $o->created_at));

        // A PO typed in at order time skips review on trust. One that matches
        // a purchase order we hold is ours; anything else is the requester's
        // own number, so it is called out for a look before it goes out.
        $ourPos = $this->knownPurchaseOrders($orders->pluck('department_po_number')->filter()->all());

        $approved = $orders->where('status', 'approved')
            // Approval sends a ready order itself, so one still here is either
            // missing its account or its send failed; say which. A PO that is
            // not one of ours is called out first.
            ->map(function (StoreOrder $o) use ($row, $ourPos) {
                // Requester-typed: keep only what a PO number is made of, so
                // nothing in it renders as markup in the card.
                $po = trim(preg_replace('/[^A-Za-z0-9 .\/_-]/', '', (string) $o->department_po_number));
                $action = $po !== '' && ! in_array(mb_strtolower($po), $ourPos, true)
                    ? trans('admin/store/general.actions_po_check', ['po' => $po])
                    : trans($o->readyForVendor()
                        ? 'admin/store/general.actions_send'
                        : 'admin/store/general.actions_needs_account');

                return $row($o, $action, $o->decided_at ?? $o->updated_at);
            });

        $quoted = $orders->where('status', 'ordered')
            ->filter(fn (StoreOrder $o) => $o->displayStatus() === 'quoted')
            ->map(fn (StoreOrder $o) => $row($o, trans($o->quoteIsExpired()
                ? 'admin/store/general.actions_quote_expired'
                : 'admin/store/general.actions_quote'), $o->quote_received_at));

        return $pending->concat($approved)->concat($quoted);
    }

    /**
     * Which of these PO numbers are purchase orders we hold, lower-cased.
     *
     * @param  array<int, string>  $numbers
     * @return array<int, string>
     */
    private function knownPurchaseOrders(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map(fn ($n) => trim((string) $n), $numbers)));

        if ($numbers === []) {
            return [];
        }

        return PurchaseOrder::query()
            ->whereIn('po_number', $numbers)
            ->pluck('po_number')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->all();
    }

    /**
     * Purchase orders the vendor has answered and we have not: a quote on
     * file that nobody has accepted, or changes the vendor sent back.
     */
    private function purchaseOrderRows(): Collection
    {
        return PurchaseOrder::query()
            ->whereIn('status', ['open', 'amended'])
            ->whereNotNull('vendor_sent_at')
            ->whereNull('quote_confirmed_at')
            ->where(fn ($q) => $q->whereNull('vendor_order_number')->orWhere('vendor_order_number', ''))
            ->orderBy('vendor_sent_at')
            ->get()
            ->map(function (PurchaseOrder $po) {
                $action = match (true) {
                    $po->vendorStage() === 'changes' => trans('admin/store/general.actions_vendor_changes'),
                    filled($po->quote_number) || $po->quote_total !== null => trans('admin/store/general.actions_quote'),
                    default => null,
                };

                return $action === null ? null : [
                    'identity' => 'po:'.$po->id.':quote',
                    'action' => $action,
                    'reference' => $po->po_number,
                    'for' => $po->title ?: $po->supplier?->name,
                    'gl' => $po->cost_center ?: null,
                    'total' => $po->quote_total !== null ? (float) $po->quote_total : null,
                    'since' => $po->vendor_changes_at ?? $po->vendor_sent_at,
                ];
            })
            ->filter()
            ->values();
    }
}
