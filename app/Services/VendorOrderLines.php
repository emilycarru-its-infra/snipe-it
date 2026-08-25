<?php

namespace App\Services;

use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Support\Collection;

/**
 * What the vendor is actually being asked to supply.
 *
 * A batch of store orders is our paperwork: sixteen references, sixteen
 * requesters, one device each. The reseller's desk needs none of that — they
 * key a parts list. So the same model across many orders becomes one line
 * with the quantity summed, and the internal references stay on our side.
 *
 * Grouped by account first, because the account decides which blanket
 * purchase order each line is placed against and a batch can legitimately
 * span more than one. Within an account, lines bundle by part: the same
 * model at a different unit price stays a separate line, since merging
 * those would state a total nobody agreed to.
 */
class VendorOrderLines
{
    /**
     * @param  Collection<int, StoreOrder>  $orders
     * @return array<int, array<string, mixed>>
     */
    public static function grouped(Collection $orders): array
    {
        $groups = [];

        foreach ($orders as $order) {
            $purchaseOrder = $order->purchaseOrder?->po_number;
            $accountKey = $purchaseOrder.'|'.$order->funding_account.'|'.$order->lease_schedule;

            // The vendor's own account number, not our internal label.
            // fundingLabel() renders "Lease · Admin · 301452-009", which
            // reads as an account that does not exist — it is our shorthand
            // with the lease schedule glued onto it. CDW has exactly four
            // accounts and places every line against one of them by number;
            // the schedule is a separate fact, on its own line.
            $groups[$accountKey] ??= [
                'purchase_order' => $purchaseOrder,
                'account' => CdwAccounts::number($order->funding_account),
                'account_purpose' => CdwAccounts::purpose($order->funding_account),
                'account_key' => $order->funding_account,
                'schedule' => $order->lease_schedule,
                'lines' => [],
                'total' => 0.0,
            ];

            foreach ($order->items as $line) {
                $lineKey = self::lineKey($line);

                if (! isset($groups[$accountKey]['lines'][$lineKey])) {
                    $groups[$accountKey]['lines'][$lineKey] = [
                        'quantity' => 0,
                        'description' => $line->description,
                        'mfr_part_number' => $line->mfr_part_number,
                        'vendor_sku' => $line->vendor_sku,
                        'warranty' => $line->catalogItem?->warrantyLabel(),
                        'bundle_url' => $line->catalogItem?->bundle_url,
                        'unit_cost' => (float) $line->unit_cost,
                        'total' => 0.0,
                    ];
                }

                $quantity = (int) $line->quantity;
                $groups[$accountKey]['lines'][$lineKey]['quantity'] += $quantity;
                $groups[$accountKey]['lines'][$lineKey]['total'] += $quantity * (float) $line->unit_cost;
                $groups[$accountKey]['total'] += $quantity * (float) $line->unit_cost;
            }
        }

        // Largest quantity first: the desk reads the big lines first, and a
        // stable order means two sends of the same batch look the same.
        return array_values(array_map(function (array $group) {
            $group['lines'] = array_values($group['lines']);
            usort($group['lines'], fn ($a, $b) => [$b['quantity'], $a['description']] <=> [$a['quantity'], $b['description']]);

            return $group;
        }, $groups));
    }

    /** Same part at the same price is the same line. */
    private static function lineKey(StoreOrderItem $line): string
    {
        return implode('|', [
            $line->catalog_item_id ?: $line->description,
            $line->mfr_part_number,
            $line->vendor_sku,
            number_format((float) $line->unit_cost, 2, '.', ''),
        ]);
    }
}
