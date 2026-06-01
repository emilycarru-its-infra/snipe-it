<?php

namespace App\Services\UserAgreements;

use App\Models\Asset;
use App\Models\OrderItem;

/**
 * Single source of truth for the dollar values that land on a
 * UserAgreement row. The auto-creators (purchase, pickup, upgrade)
 * and any future call site (manual create, importers, reports) all
 * route their cost lookups through this service so the
 * "where does a buyout cost actually come from?" question has one
 * answer at any point in time.
 *
 * Current sources — to evolve without changing call sites:
 *
 *   base    config('forms.pickup_auto_create.base_program_price')
 *   device  sum of asset's order_items (unit_cost × qty + warranty_cost),
 *           falling back to Asset::purchase_cost. The order_items path
 *           captures AppleCare and other warranty add-ons that ride
 *           alongside the Mac on the same PO; purchase_cost typically
 *           only carries the bare hardware line.
 *   top_up  max(0, device - base)
 *   buyout  same as device — for end-of-lease devices the residual we
 *           negotiate is computed against the FULL acquisition cost,
 *           including AppleCare. Historical lease assets predate the
 *           Orders module, so they fall through to purchase_cost and
 *           need manual buyout entries (see L002916 / L002978 / L002979
 *           / L002974 for known cases as of 2026-06-01).
 *
 * All methods return nullable floats — `null` means "unknown, leave
 * blank on the row". Callers should never invent zero as a stand-in;
 * downstream views format nulls distinctly from $0.00.
 */
class CostResolver
{
    public function baseProgramPrice(): ?float
    {
        $value = config('forms.pickup_auto_create.base_program_price');
        return $value === null ? null : (float) $value;
    }

    public function deviceCost(Asset $asset): ?float
    {
        $orderItemsTotal = $this->orderItemsTotal($asset);
        if ($orderItemsTotal !== null) {
            return $orderItemsTotal;
        }

        return $asset->purchase_cost ? (float) $asset->purchase_cost : null;
    }

    public function topUpAmount(Asset $asset, ?float $deviceCost = null, ?float $basePrice = null): ?float
    {
        $device = $deviceCost ?? $this->deviceCost($asset);
        $base   = $basePrice  ?? $this->baseProgramPrice();

        if ($device === null || $base === null) {
            return null;
        }

        return max(0.0, $device - $base);
    }

    public function buyoutCost(Asset $asset): ?float
    {
        return $this->deviceCost($asset);
    }

    /**
     * Sum every order_items row whose `(item_type, item_id)` morph
     * points at this asset, computing `unit_cost × max(quantity, 1) +
     * warranty_cost`. Returns null when the asset has no order_items
     * rows at all so callers can fall back to the legacy purchase_cost
     * path; returns 0.0 only if rows exist but every column is literal
     * zero.
     */
    private function orderItemsTotal(Asset $asset): ?float
    {
        $row = OrderItem::query()
            ->where('item_type', Asset::class)
            ->where('item_id', $asset->id)
            ->selectRaw('COALESCE(SUM(unit_cost * GREATEST(quantity, 1) + COALESCE(warranty_cost, 0)), 0) AS total, COUNT(*) AS line_count')
            ->first();

        if (! $row || (int) $row->line_count === 0) {
            return null;
        }

        return (float) $row->total;
    }
}
