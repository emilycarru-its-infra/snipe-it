<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * A quote teaches the catalog its prices.
 *
 * The catalog starts life as estimates — "~C$3300" from the reseller's
 * periodic list — and every order sent from it is priced on those. When the
 * vendor quotes the order back, every line that came from a catalog row now
 * has a real number, and that number is worth more than the estimate for the
 * next order of the same part. So it is written back: the row becomes
 * `quoted`, at the quoted unit price, dated, with the quote it came from as
 * its source and the vendor's expiry as its own. Over the year the catalog
 * turns from estimates into what was actually paid, one quote at a time.
 *
 * The part numbers are re-verified at the same moment. CDW quoting a line
 * against an EDC and MFR# is the strongest confirmation there is that both
 * are current — stronger than a warehouse list — so the quarterly staleness
 * check resets here.
 *
 * Lines that resolve to no row — freight, a fee, a build nobody has priced
 * — teach nothing; the catalog does not grow rows from an order.
 */
class CatalogQuoteWriteback
{
    /**
     * Write the order's quoted prices onto the catalog rows its lines came
     * from. Returns the rows updated, so a caller can say how many.
     *
     * @return Collection<int, CatalogItem>
     */
    public function apply(Order $order): Collection
    {
        if (blank($order->quote_number)) {
            return collect();
        }

        $order->loadMissing('supplier', 'items.catalogItem');

        $source = trans('admin/purchase-orders/general.catalog_quote_source', [
            'supplier' => $order->supplier?->name ?: trans('general.supplier'),
            'quote' => $order->quote_number,
        ]);

        // The row is dated by the quote, not by the keystroke: an old quote
        // recorded late is still an old quote, and a row that already holds a
        // newer one keeps it. Same-day quotes overwrite, latest write wins.
        $quotedOn = ($order->quote_confirmed_at ?? $order->vendor_changes_at ?? $order->vendor_sent_at ?? now())->toDateString();

        $updated = collect();

        foreach ($order->vendorOrderLines() as $line) {
            $row = $this->rowFor($line, $order);

            if (! $row || $line->unit_cost === null) {
                continue;
            }

            if ($row->quoted_at !== null && $row->quoted_at->toDateString() > $quotedOn) {
                continue;
            }

            $row->forceFill([
                'unit_cost' => (float) $line->unit_cost,
                'price_type' => 'quoted',
                'quoted_at' => $quotedOn,
                'expires_at' => $order->quote_expires_at?->toDateString(),
                'source' => $source,
                'part_numbers_verified_at' => now(),
            ])->save();

            $updated->push($row);
        }

        return $updated;
    }

    /**
     * The catalog row a line came from — its own when it was picked from the
     * catalog, otherwise the supplier's row carrying the same EDC, which is
     * how a line typed by hand against a known part still teaches the row.
     */
    private function rowFor($line, Order $order): ?CatalogItem
    {
        if ($line->catalog_item_id && $line->catalogItem) {
            return $line->catalogItem;
        }

        if (blank($line->vendor_sku)) {
            return null;
        }

        return CatalogItem::query()
            ->when($order->supplier_id, fn ($q) => $q->where('supplier_id', $order->supplier_id))
            ->where('vendor_sku', $line->vendor_sku)
            ->orderBy('id')
            ->first();
    }
}
