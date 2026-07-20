<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\LeaseDecision;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\UserAgreement;
use Illuminate\Support\Collection;

/**
 * Stage data for the procurement pipeline view on the dashboard.
 *
 * The pipeline reads the FY as six stages — budgeting, ordering,
 * processing, deploying, reconciling, completed — and places every
 * order/device in exactly one, derived from fields that already exist:
 *
 *   budgeting   planned orders (is_planned), no PO attached yet
 *   ordering    actual orders carrying a PO, nothing received
 *   processing  received line items whose assets are not yet checked out
 *   deploying   user agreements in flight (quoted → signed)
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
     * How many cards a board column shows before collapsing to a
     * "+N more" row. Keeps the dashboard render bounded on years with
     * hundreds of orders.
     */
    private const CARD_CAP = 6;

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
        $deploying = self::deployingCounts();
        $returns = self::returnLanes();

        return [
            'planned' => $planned['cards'],
            'plannedMore' => $planned['more'],
            'open' => $open['cards'],
            'openMore' => $open['more'],
            'processing' => $processing['cards'],
            'processingMore' => $processing['more'],
            'stagedItemCount' => $stagedItemCount,
            'deploying' => $deploying,
            'pendingInvoices' => $pendingInvoices['cards'],
            'pendingInvoicesMore' => $pendingInvoices['more'],
            'completed' => $completed['cards'],
            'completedMore' => $completed['more'],
            'completedCount' => $completed['cards']->count() + $completed['more'],
            'returns' => $returns,
            'activeStage' => self::activeStage($fy),
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
     * Invoices awaiting approval — the reconciling column. FY attribution
     * follows the order each invoice sits on, same as the dashboard's
     * invoiced totals.
     */
    private static function pendingInvoiceCards(?string $fy): array
    {
        $invoices = OrderInvoice::where('approval_status', 'pending')
            ->when($fy, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('fiscal_year', $fy)))
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
     * Agreements in flight, by lifecycle stage. Agreements carry no FY;
     * the in-flight set is small and current by construction.
     */
    private static function deployingCounts(): array
    {
        $counts = UserAgreement::whereIn('lifecycle_stage', ['quoted', 'agreement_sent', 'agreement_signed'])
            ->selectRaw('lifecycle_stage, count(*) as n')
            ->groupBy('lifecycle_stage')
            ->pluck('n', 'lifecycle_stage');

        return [
            'quoted' => (int) ($counts['quoted'] ?? 0),
            'sent' => (int) ($counts['agreement_sent'] ?? 0),
            'signed' => (int) ($counts['agreement_signed'] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * The reverse pipeline: outgoing lease devices, laned by decision
     * state — pending call, return in prep, closed out.
     */
    private static function returnLanes(): array
    {
        $decisions = LeaseDecision::whereIn('status', ['pending', 'approved', 'completed'])
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
            in_array($now->month, [8, 9], true) => 'processing',
            in_array($now->month, [10, 11], true) => 'deploying',
            default => 'reconciling',
        };
    }

    /**
     * Line-item rows for a card's lightbox, serial included when the line
     * is a received asset.
     */
    private static function itemRows(Order $order): array
    {
        return $order->items->take(self::ITEM_CAP)->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => (int) $item->quantity,
            'unit_cost' => (float) $item->unit_cost,
            'received_at' => $item->received_at?->format('Y-m-d'),
            'serial' => $item->item instanceof Asset ? $item->item->serial : null,
            'deployed' => $item->item instanceof Asset && ! is_null($item->item->assigned_to),
        ])->values()->all();
    }

    /**
     * First CARD_CAP cards plus how many were cut.
     */
    private static function cap(Collection $cards): array
    {
        return [
            'cards' => $cards->take(self::CARD_CAP)->values(),
            'more' => max(0, $cards->count() - self::CARD_CAP),
        ];
    }
}
