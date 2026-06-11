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

    /**
     * Seed an approved budget for $fy and commit $committed against it via
     * an asset bought in that fiscal year.
     */
    private function seedYear(string $fy, float $approved, float $committed): void
    {
        BudgetAllocation::create([
            'fiscal_year' => $fy,
            'amount'      => $approved,
            'source'      => 'forecast',
            'created_by'  => User::factory()->create()->id,
        ]);

        if ($committed > 0) {
            $po = PurchaseOrder::factory()->create(['fiscal_year' => $fy]);
            $this->commitViaAsset($po->po_number, $committed, '2025-06-01');
        }
    }

    public function test_carry_forward_posts_prior_year_unspent_into_target_fy()
    {
        // FY2025-26: $10,000 approved, $3,000 committed → $7,000 unspent.
        $this->seedYear('FY2025-26', 10000, 3000);

        $this->actingAs($this->superuser())
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27'])
            ->assertRedirect(route('reports.procurement', ['fiscal_year' => 'FY2026-27']));

        $carried = BudgetAllocation::where('fiscal_year', 'FY2026-27')
            ->where('source', 'carry_forward')
            ->first();

        $this->assertNotNull($carried);
        $this->assertEquals(7000.00, (float) $carried->amount);
    }

    public function test_carry_forward_is_idempotent_per_target_year()
    {
        $this->seedYear('FY2025-26', 10000, 3000);
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
        // Committed meets approved → no unspent to carry.
        $this->seedYear('FY2025-26', 5000, 5000);

        $this->actingAs($this->superuser())
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27']);

        $this->assertEquals(0, BudgetAllocation::where('fiscal_year', 'FY2026-27')
            ->where('source', 'carry_forward')->count());
    }

    public function test_carry_forward_falls_back_to_po_budgets_when_ledger_empty()
    {
        // No allocation ledger for FY2025-26 — approved falls back to the
        // year's PO budgets (the same fallback the dashboard tile uses):
        // $9,000 budgets − $2,500 asset-committed → $6,500 carried.
        PurchaseOrder::factory()->create([
            'po_number' => 'P0088001', 'budget' => 4000, 'fiscal_year' => 'FY2025-26',
        ]);
        PurchaseOrder::factory()->create([
            'po_number' => 'P0088002', 'budget' => 5000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0088001', 2500, '2025-06-01');

        // An asset bought outside FY2025-26 must not reduce the carry.
        $this->commitViaAsset('P0088002', 1000, '2026-06-01');

        $this->actingAs($this->superuser())
            ->post(route('budget_allocations.carry_forward'), ['target_fiscal_year' => 'FY2026-27']);

        $carried = BudgetAllocation::where('fiscal_year', 'FY2026-27')
            ->where('source', 'carry_forward')
            ->first();

        $this->assertNotNull($carried);
        $this->assertEquals(6500.00, (float) $carried->amount);
    }
}
