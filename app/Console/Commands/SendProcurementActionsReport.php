<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PostsReportCards;
use App\Mail\EmailDelivery;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
    use PostsReportCards;

    public const KEY = 'report.procurement_actions';

    protected $signature = 'snipeit:procurement-actions
                            {--dry-run : List what would be posted without posting it}';

    protected $description = 'Post the store orders and vendor quotes waiting on procurement to Teams.';

    public function handle(): int
    {
        $rows = $this->rows();

        if ($rows->isEmpty()) {
            $this->info('Nothing waiting on procurement.');

            return self::SUCCESS;
        }

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

        $this->postReportCard(
            self::KEY,
            trans('admin/store/general.actions_report_title'),
            'attention',
            $this->columns(),
            $rows,
            fn (array $row) => $this->cells($row),
            $rows->countBy('action')->all(),
            route('procurement.approvals'),
        );

        $this->info('Posted '.$rows->count().' item(s).');

        return self::SUCCESS;
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
     * @param  array{action: string, reference: string, for: ?string, gl: ?string, total: ?float, since: ?CarbonInterface}  $row
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
     * @return Collection<int, array{action: string, reference: string, for: ?string, gl: ?string, total: ?float, since: ?CarbonInterface}>
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
            ->map(function (StoreOrder $o) use ($row, $ourPos) {
                $po = trim((string) $o->department_po_number);
                $action = $po !== '' && ! in_array(mb_strtolower($po), $ourPos, true)
                    ? trans('admin/store/general.actions_po_check', ['po' => $po])
                    : trans('admin/store/general.actions_send');

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
