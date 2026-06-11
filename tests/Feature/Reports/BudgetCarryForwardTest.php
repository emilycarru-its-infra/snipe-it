<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\BudgetAllocation;
use App\Models\CustomField;
use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

class BudgetCarryForwardTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    /**
     * Commit $committed against $poNumber from the asset source of truth:
     * a device bought inside the FY carrying the PO on its "PO Number"
     * field — the same engine the dashboard's Committed tile reads.
     */
    private function commitViaAsset(string $poNumber, float $committed, string $purchaseDate): void
    {
        $poField = CustomField::where('name', 'PO Number')->first()
            ?? CustomField::factory()->create(['name' => 'PO Number']);

        $asset = Asset::factory()->create([
            'purchase_cost' => $committed,
            'purchase_date' => $purchaseDate,
        ]);
        Asset::query()->whereKey($asset->id)->update([$poField->db_column => $poNumber]);
    }

    public function test_carry_forward_posts_prior_year_unused_po_budget_into_target_fy()
    {
        // FY2025-26: one PO with a $10,000 envelope, $3,000 committed
        // against it → $7,000 unused.
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077001', 'budget' => 10000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077001', 3000, '2025-06-01');

        $this->actingAs($this->superuser())
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27'])
            ->assertRedirect(route('reports.procurement', ['fiscal_year' => 'FY2026-27']));

        $carried = BudgetAllocation::where('fiscal_year', 'FY2026-27')
            ->where('source', 'carry_forward')
            ->first();

        $this->assertNotNull($carried);
        $this->assertEquals(7000.00, (float) $carried->amount);
    }

    public function test_carry_forward_sums_per_po_unused_and_nets_overspend()
    {
        // Two envelopes: P0077010 $4,000 budget / $4,500 committed (over
        // by $500) and P0077011 $5,000 / $2,000 (under by $3,000) → net
        // $2,500. Spend filed against a PO with no budget record
        // (P0077099) doesn't drain either envelope.
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077010', 'budget' => 4000, 'fiscal_year' => 'FY2025-26',
        ]);
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077011', 'budget' => 5000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077010', 4500, '2025-06-01');
        $this->commitViaAsset('P0077011', 2000, '2025-07-01');
        $this->commitViaAsset('P0077099', 1500, '2025-08-01');

        $this->actingAs($this->superuser())
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27']);

        $carried = BudgetAllocation::where('fiscal_year', 'FY2026-27')
            ->where('source', 'carry_forward')
            ->first();

        $this->assertNotNull($carried);
        $this->assertEquals(2500.00, (float) $carried->amount);
    }

    public function test_carry_forward_ignores_committed_outside_the_source_fy()
    {
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077020', 'budget' => 6000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077020', 2000, '2025-06-01');

        // Same PO, but the asset was bought in FY2026-27 — its cost
        // belongs to that year, not the envelope being carried.
        $this->commitViaAsset('P0077020', 3000, '2026-06-01');

        $this->actingAs($this->superuser())
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27']);

        $carried = BudgetAllocation::where('fiscal_year', 'FY2026-27')
            ->where('source', 'carry_forward')
            ->first();

        $this->assertNotNull($carried);
        $this->assertEquals(4000.00, (float) $carried->amount);
    }

    public function test_carry_forward_is_idempotent_per_target_year()
    {
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077030', 'budget' => 10000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077030', 3000, '2025-06-01');
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27']);
        $this->actingAs($superuser)
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27']);

        $this->assertEquals(1, BudgetAllocation::where('fiscal_year', 'FY2026-27')
            ->where('source', 'carry_forward')->count());
    }

    public function test_carry_forward_does_nothing_when_prior_year_fully_spent()
    {
        // Committed meets the envelope → no unused budget to carry.
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077040', 'budget' => 5000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077040', 5000, '2025-06-01');

        $this->actingAs($this->superuser())
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27']);

        $this->assertEquals(0, BudgetAllocation::where('fiscal_year', 'FY2026-27')
            ->where('source', 'carry_forward')->count());
    }

    public function test_carry_forward_does_nothing_without_source_year_pos()
    {
        // An approved-budget allocation alone is not an envelope — only
        // PO budgets carry.
        BudgetAllocation::create([
            'fiscal_year' => 'FY2025-26',
            'amount'      => 10000,
            'source'      => 'forecast',
            'created_by'  => User::factory()->create()->id,
        ]);

        $this->actingAs($this->superuser())
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27']);

        $this->assertEquals(0, BudgetAllocation::where('fiscal_year', 'FY2026-27')
            ->where('source', 'carry_forward')->count());
    }
}
