<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\LeaseDecision;
use App\Models\Order;
use App\Models\OrderInvoice;
use Illuminate\Support\Collection;

/**
 * Stage data for the procurement pipeline view on the dashboard.
 *
 * The pipeline reads the FY as five stages — budgeting, ordering,
 * deploying, reconciling, completed — and places every order/device in
 * exactly one, derived from fields that already exist:
 *
 *   budgeting   planned orders (is_planned), no PO attached yet
 *   ordering    actual orders carrying a PO, nothing received
 *   deploying   the whole physical span: received line items whose assets
 *               are not yet checked out (the build() 'processing' keys).
 *               Devices only — an agreement is paperwork attached to one
 *               of these laptops, not a separate thing arriving, and
 *               counting both double counted the same hardware. The
 *               per-device detail lives on the Deployments board; an
 *               order stays here until that side confirms the device is
 *               in place or in hand.
 *   reconciling invoices pending approval — deployed but money trail open
 *   completed   received orders with every invoice approved and every
 *               asset checked out
 *
 * The hard boundary out of budgeting is a PO number: a planned order
 * converts to an actual order only once purchase_order_id is set
 * (enforced in OrdersController@update).
 */
class ProcurementPipeline
{
    /**
     * Line items listed inside a card's lightbox before it defers to the
     * full order page.
     */
    private const ITEM_CAP = 20;

    public static function build(?string $fy): array
    {
        $planned = self::plannedCards($fy);
        $open = self::openOrderCards($fy);
        [$processing, $completed, $stagedItemCount] = self::receivedCards($fy);
        $pendingInvoices = self::pendingInvoiceCards($fy);
        $returns = self::returnLanes();

        return [
            'planned' => $planned['cards'],
            'plannedMore' => $planned['more'],
            'open' => $open['cards'],
            'openMore' => $open['more'],
            'processing' => $processing['cards'],
            'processingMore' => $processing['more'],
            'stagedItemCount' => $stagedItemCount,
            'pendingInvoices' => $pendingInvoices['cards'],
            'pendingInvoicesMore' => $pendingInvoices['more'],
            'completed' => $completed['cards'],
            'completedMore' => $completed['more'],
            'completedCount' => $completed['cards']->count() + $completed['more'],
            'returns' => $returns,
            'activeStage' => self::activeStage($fy),
            // Agreements out for signature — an indicator, not cards: it
            // counts agreements rather than devices (the device counts on
            // this column already come from staging), and links out to the
            // ledger where the rows are actually worked.
            'awaitingSignature' => \App\Models\UserAgreement::query()
                ->whereIn('lifecycle_stage', ['quoted', 'agreement_sent'])
                ->forProgramFiscalYear($fy)
                ->count(),
            // Unplanned capital asks that aren't a PO yet — ministry funding
            // and other ad-hoc requests sitting in the requisition queue.
            'openRequisitions' => \App\Models\Requisition::whereIn('status', ['draft', 'submitted', 'requisitioned'])->count(),
            // In-flight work that belongs ON the board, not in side tables:
            // open requisitions as Budgeting cards (their exit gate is the
            // PO number, same as planned orders), and store orders awaiting
            // review as Ordering cards linking to the approval queue.
            'requisitionCards' => \App\Models\Requisition::query()
                ->whereIn('status', ['draft', 'submitted', 'requisitioned'])
                ->when($fy, fn ($q) => $q->where(fn ($w) => $w->where('fiscal_year', $fy)->orWhereNull('fiscal_year')))
                ->with('items')
                ->orderBy('requisition_number')
                ->get()
                ->map(fn ($requisition) => [
                    'id' => $requisition->id,
                    'number' => $requisition->requisition_number ?: ('REQ-'.$requisition->id),
                    'title' => $requisition->title,
                    'status' => $requisition->status,
                    'total' => $requisition->total(),
                    'items' => $requisition->items->map(fn ($line) => [
                        'description' => $line->description,
                        'quantity' => (int) $line->quantity,
                        'unit_cost' => (float) $line->unit_cost,
                    ])->all(),
                ])->values()->all(),
            // Orders that have gone to the vendor. They are past budgeting —
            // the purchase order exists and the vendor is holding the order —
            // but no Order row exists yet, because that arrives with the
            // vendor's own order number when they ship. Without these, an
            // order vanishes off the board for the weeks between sending it
            // and its first shipment, which is exactly when somebody asks
            // where it is.
            'sentRequisitionCards' => \App\Models\Requisition::query()
                ->whereNotNull('vendor_sent_at')
                ->whereNotNull('purchase_order_id')
                ->when($fy, fn ($q) => $q->where(fn ($w) => $w->where('fiscal_year', $fy)->orWhereNull('fiscal_year')))
                // Once the vendor's order lands against the same purchase
                // order, that Order card is the truth and this one would
                // double-count it.
                ->whereNotExists(fn ($q) => $q->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.purchase_order_id', 'requisitions.purchase_order_id')
                    ->whereNull('orders.deleted_at'))
                ->with('items', 'purchaseOrder', 'supplier')
                ->orderBy('vendor_sent_at')
                ->get()
                ->map(fn ($requisition) => [
                    'id' => $requisition->id,
                    'number' => $requisition->display_name,
                    'title' => $requisition->title,
                    'supplier' => $requisition->supplier?->name,
                    'account' => $requisition->fundingLabel(),
                    'quote_number' => $requisition->quote_number,
                    'sent_at' => $requisition->vendor_sent_at?->format('Y-m-d'),
                    'total' => $requisition->vendorTotal(),
                    'items' => $requisition->items->map(fn ($line) => [
                        'description' => $line->description,
                        'quantity' => (int) $line->quantity,
                        'unit_cost' => (float) $line->unit_cost,
                    ])->all(),
                ])->values()->all(),
            // Pending and approved both, because approving one used to make
            // it disappear: this was the only place the board looked at
            // store orders, and nothing downstream picks an order up until
            // it has been pulled into a requisition and given a PO. An
            // approved order had nowhere to live in between, so somebody
            // approving one then went looking for it and found nothing.
            'storeQueue' => \App\Models\StoreOrder::query()
                ->whereIn('status', ['pending', 'approved'])
                ->with(['user', 'items'])
                ->orderBy('created_at')
                ->get()
                ->map(fn ($order) => [
                    'id' => $order->id,
                    'number' => $order->reference(),
                    'requester' => $order->user?->present()->fullName ?? '',
                    'status' => $order->status,
                    'total' => (float) $order->items->sum(fn ($item) => (float) $item->unit_cost * (int) $item->quantity),
                    'items' => $order->items->map(fn ($line) => [
                        'description' => $line->description,
                        'quantity' => (int) $line->quantity,
                        'unit_cost' => (float) $line->unit_cost,
                    ])->all(),
                ])->values()->all(),
        ];
    }

    /**
     * Planned (forecast) orders — the budgeting column. A card carries
     * whether a PO is already attached, since that's the exit gate.
     */
    private static function plannedCards(?string $fy): array
    {
        $orders = Order::planned()
            ->when($fy, fn ($q) => $q->where('fiscal_year', $fy))
            ->with(['items', 'purchaseOrder'])
            ->orderBy('order_number')
            ->get();

        return self::cap($orders->map(fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'total' => (float) $order->items->sum->lineTotal(),
            'items_count' => $order->items->count(),
            'po_number' => $order->purchaseOrder?->po_number,
            'items' => self::itemRows($order),
        ]));
    }

    /**
     * Actual orders with nothing received yet — the ordering column.
     */
    private static function openOrderCards(?string $fy): array
    {
        $orders = Order::actual()
            ->when($fy, fn ($q) => $q->where('fiscal_year', $fy))
            ->whereIn('status', ['ordered', 'shipped'])
            ->with(['items', 'purchaseOrder', 'supplier'])
            ->orderBy('order_date')
            ->get();

        return self::cap($orders->map(fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'supplier' => $order->supplier?->name,
            'expected_date' => $order->expected_date?->format('Y-m-d'),
            'total' => (float) $order->items->sum->lineTotal(),
            'items_count' => $order->items->count(),
            'po_number' => $order->purchaseOrder?->po_number,
            'items' => self::itemRows($order),
        ]));
    }

    /**
     * Orders with received line items, split between processing (assets
     * still waiting to be checked out) and completed (everything deployed
     * and every invoice approved). Also returns the total count of staged
     * items for the chevron figure.
     */
    private static function receivedCards(?string $fy): array
    {
        $orders = Order::actual()
            ->when($fy, fn ($q) => $q->where('fiscal_year', $fy))
            ->whereIn('status', ['partially_received', 'received'])
            ->with(['items.item', 'purchaseOrder', 'invoices'])
            ->orderByDesc('received_date')
            ->get();

        $processing = collect();
        $completed = collect();
        $stagedItemCount = 0;

        foreach ($orders as $order) {
            // Staged = received onto the books but not in anyone's hands
            // yet. Only asset lines can deploy; other item types count as
            // done the moment they arrive.
            $staged = $order->items->filter(
                fn ($item) => $item->received_at
                    && $item->item instanceof Asset
                    && is_null($item->item->assigned_to)
            );
            $stagedItemCount += $staged->count();

            $card = [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'received_date' => $order->received_date?->format('Y-m-d'),
                'staged_count' => $staged->count(),
                'items_count' => $order->items->count(),
                'total' => (float) $order->items->sum->lineTotal(),
                'po_number' => $order->purchaseOrder?->po_number,
                'items' => self::itemRows($order),
            ];

            $invoicesSettled = $order->invoices->isNotEmpty()
                && $order->invoices->every(fn ($invoice) => $invoice->approval_status === 'approved');

            if ($staged->isEmpty() && $order->status === 'received' && $invoicesSettled) {
                $completed->push($card);
            } elseif ($staged->isNotEmpty()) {
                $processing->push($card);
            }
            // Fully deployed but invoices still open: the order's money
            // trail shows up in reconciling via its pending invoices, so
            // it doesn't need a card of its own here.
        }

        return [self::cap($processing), self::cap($completed), $stagedItemCount];
    }

    /**
     * Invoices awaiting approval — the reconciling column. FY attribution goes
     * through OrderInvoice::scopeForFiscalYear so this column and the chevron
     * above it apply the same rule; they previously did not, and an invoice on
     * an order with no fiscal_year was counted in the header while its card was
     * dropped from the column.
     */
    private static function pendingInvoiceCards(?string $fy): array
    {
        $invoices = OrderInvoice::where('approval_status', 'pending')
            ->forFiscalYear($fy)
            ->with('order')
            ->orderBy('invoice_date')
            ->get();

        return self::cap($invoices->map(fn (OrderInvoice $invoice) => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'order_number' => $invoice->order?->order_number,
            'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
            'subtotal' => (float) $invoice->subtotal,
            'total' => (float) $invoice->total,
        ]));
    }

    /**
     * The reverse pipeline: outgoing lease devices, laned by decision
     * state — pending call, return in prep, closed out.
     */
    private static function returnLanes(): array
    {
        $decisions = LeaseDecision::whereIn('status', ['pending', 'approved', 'completed'])
            // Note-only rows (no decision_type) carry a schedule's plan note,
            // not a return/buyout call — they don't belong in the lanes.
            ->whereNotNull('decision_type')
            ->orderByDesc('decision_date')
            ->get();

        $row = fn (LeaseDecision $decision) => [
            'id' => $decision->id,
            'contract_reference' => $decision->contract_reference,
            'decision_type' => $decision->decision_type,
            'decision_date' => $decision->decision_date?->format('Y-m-d'),
            'notes' => $decision->notes,
        ];

        return [
            'pending' => self::cap($decisions->where('status', 'pending')->map($row)),
            'prep' => self::cap(
                $decisions->where('status', 'approved')
                    ->whereIn('decision_type', ['return', 'replace'])
                    ->map($row)
            ),
            'closed' => self::cap($decisions->where('status', 'completed')->map($row)),
        ];
    }

    /**
     * Which chevron the calendar says the FY is in right now — only
     * meaningful when the selected FY is the current one (April-start).
     * Completed never highlights; it's a terminal bucket, not a season.
     */
    private static function activeStage(?string $fy): ?string
    {
        $now = now();
        $startYear = $now->month >= 4 ? $now->year : $now->year - 1;
        $currentFy = sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);

        if ($fy !== $currentFy) {
            return null;
        }

        return match (true) {
            in_array($now->month, [2, 3, 4, 5], true) => 'budgeting',
            in_array($now->month, [6, 7], true) => 'ordering',
            in_array($now->month, [8, 9, 10, 11], true) => 'deploying',
            default => 'reconciling',
        };
    }

    /**
     * Line-item rows for a card's lightbox. The Item column mirrors the
     * order page: the linked record's name and tag when the line resolves
     * to an inventory record, otherwise the free-text description.
     */
    private static function itemRows(Order $order): array
    {
        return $order->items->take(self::ITEM_CAP)->map(function ($item) {
            $linked = $item->item;
            $label = $item->description;
            if ($linked instanceof Asset) {
                $label = trim(($linked->name ?: (string) $linked->model?->name).' #'.$linked->asset_tag);
            } elseif ($linked) {
                $label = (string) $linked->getAttribute('name');
            }

            return [
                'item_label' => $label,
                'quantity' => (int) $item->quantity,
                'unit_cost' => (float) $item->unit_cost,
                'received_at' => $item->received_at?->format('Y-m-d'),
                'serial' => $linked instanceof Asset ? $linked->serial : null,
                'deployed' => $linked instanceof Asset && ! is_null($linked->assigned_to),
            ];
        })->values()->all();
    }

    /**
     * Every card in the column.
     *
     * The board used to show the first six and collapse the rest to a
     * "+N more" row, which meant a stage's real workload was a number the
     * reader had to trust rather than a list they could work from — five
     * hidden invoices in Reconciling is exactly the sort of thing this
     * board exists to surface. The columns scroll instead; the 'more' key
     * stays at zero so the counts and the callers that add it still read.
     */
    private static function cap(Collection $cards): array
    {
        return [
            'cards' => $cards->values(),
            'more' => 0,
        ];
    }
}
