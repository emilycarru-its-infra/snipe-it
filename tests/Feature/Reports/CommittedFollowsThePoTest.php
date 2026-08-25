<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\PurchaseOrder;
use App\Services\AssetCommitted;
use Tests\TestCase;

/**
 * Which fiscal year a committed dollar belongs to.
 *
 * It belongs to the year of its PURCHASE ORDER, not the year the box
 * arrived. Scoping by purchase_date alone put last year's orders into this
 * year's report the moment they were delivered after April 1, and did it
 * twice over: that spend had already been deducted from last year's budget
 * when the carry-forward was computed, so it reduced the new year's picture
 * a second time.
 */
class CommittedFollowsThePoTest extends TestCase
{
    private function assetOn(string $poNumber, string $purchaseDate, float $cost): Asset
    {
        return Asset::factory()->create([
            'po_number' => $poNumber,
            'purchase_date' => $purchaseDate,
            'purchase_cost' => $cost,
        ]);
    }

    public function test_a_prior_year_po_keeps_its_spend_when_the_kit_lands_in_the_new_year()
    {
        PurchaseOrder::factory()->create(['po_number' => 'P0025420', 'fiscal_year' => 'FY2025-26', 'budget' => 226256.15]);

        // Ordered last year, delivered in April — the new fiscal year.
        $this->assetOn('P0025420', '2026-04-17', 4079.19);

        $this->assertArrayNotHasKey('P0025420', AssetCommitted::byPo('FY2026-27'));
        $this->assertEqualsWithDelta(4079.19, AssetCommitted::byPo('FY2025-26')['P0025420'] ?? 0.0, 0.01);
    }

    public function test_this_years_po_counts_in_this_year_whenever_it_was_dated()
    {
        PurchaseOrder::factory()->create(['po_number' => 'P0026041', 'fiscal_year' => 'FY2026-27', 'budget' => 178640.00]);

        $this->assetOn('P0026041', '2026-04-17', 2100.00);

        $this->assertEqualsWithDelta(2100.00, AssetCommitted::byPo('FY2026-27')['P0026041'] ?? 0.0, 0.01);
        $this->assertArrayNotHasKey('P0026041', AssetCommitted::byPo('FY2025-26'));
    }

    public function test_a_po_the_ledger_has_never_heard_of_falls_back_to_its_date()
    {
        // Nothing else can place it, so the purchase date still decides.
        $this->assetOn('P0025810', '2026-04-17', 909.91);

        $this->assertEqualsWithDelta(909.91, AssetCommitted::byPo('FY2026-27')['P0025810'] ?? 0.0, 0.01);
        $this->assertArrayNotHasKey('P0025810', AssetCommitted::byPo('FY2025-26'));
    }

    public function test_unscoped_committed_still_sees_everything()
    {
        PurchaseOrder::factory()->create(['po_number' => 'P0025420', 'fiscal_year' => 'FY2025-26', 'budget' => 1000.00]);
        $this->assetOn('P0025420', '2026-04-17', 4079.19);

        // The carry-forward reads unscoped, because a prior-year envelope is
        // drained by spend whenever it lands.
        $this->assertEqualsWithDelta(4079.19, AssetCommitted::byPo()['P0025420'] ?? 0.0, 0.01);
    }
}
