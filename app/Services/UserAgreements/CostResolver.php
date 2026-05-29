<?php

namespace App\Services\UserAgreements;

use App\Models\Asset;

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
 *   device  Asset::purchase_cost
 *   top_up  max(0, device - base)
 *   buyout  Asset::purchase_cost
 *           (per 2026-05-28: purchase_cost is the authoritative buyout
 *           number for end-of-lease devices; CSI feed integration
 *           lands here when ready)
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
        return $asset->purchase_cost === null ? null : (float) $asset->purchase_cost;
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
        return $asset->purchase_cost === null ? null : (float) $asset->purchase_cost;
    }
}
