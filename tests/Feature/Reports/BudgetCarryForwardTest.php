<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\BudgetAllocation;
use App\Models\CustomField;
use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

/**
 * The live carry-forward on the procurement dashboard: a selected fiscal
 * year's Approved Budget includes the prior FY's unused PO budget,
 * computed at render time from the POs and asset-committed — no posted
 * snapshot to delete and re-post when the committed data is corrected.
 */
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
     * A zero-budget PO in the target FY, so the year is a selectable
     * dashboard scope without contributing to the budget figures.
     */
    private function makeTargetFySelectable(string $fy = 'FY2026-27'): void
    {
        PurchaseOrder::factory()->create([
            'po_number' => 'P0099900', 'budget' => 0, 'fiscal_year' => $fy,
        ]);
    }

    private function inclCarryText(string $amount, string $source = 'FY2025-26'): string
    {
        return trans('admin/purchase-orders/general.card_budget_incl_carry', [
            'amount' => $amount,
            'source' => $source,
        ]);
    }

    public function test_dashboard_includes_live_carry_from_prior_fy_unused_po_budget()
    {
        // FY2025-26: a $10,000 envelope with $3,000 committed → $7,000
        // unused, carried live into FY2026-27.
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077001', 'budget' => 10000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077001', 3000, '2025-06-01');
        $this->makeTargetFySelectable();

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee($this->inclCarryText('$7,000.00'))
            ->assertSee(trans('admin/budget-allocations/general.live'));
    }

    public function test_live_carry_nets_per_po_and_ignores_budgetless_po_spend()
    {
        // Two envelopes: $4,000/$4,500 committed (over by $500) and
        // $5,000/$2,000 (under by $3,000) → net $2,500 carried. Spend on a
        // PO with no budget record doesn't drain either envelope.
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077010', 'budget' => 4000, 'fiscal_year' => 'FY2025-26',
        ]);
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077011', 'budget' => 5000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077010', 4500, '2025-06-01');
        $this->commitViaAsset('P0077011', 2000, '2025-07-01');
        $this->commitViaAsset('P0077099', 1500, '2025-08-01');
        $this->makeTargetFySelectable();

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee($this->inclCarryText('$2,500.00'));
    }

    public function test_manual_carry_forward_allocation_overrides_live()
    {
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077020', 'budget' => 10000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077020', 3000, '2025-06-01');

        BudgetAllocation::create([
            'fiscal_year' => 'FY2026-27',
            'amount'      => 1234.56,
            'source'      => 'carry_forward',
            'created_by'  => User::factory()->create()->id,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee('$1,234.56')
            ->assertDontSee($this->inclCarryText('$7,000.00'));
    }

    public function test_all_years_view_excludes_live_carry()
    {
        // A carry is an intra-year transfer — adding it to the all-years
        // pot would double-count the prior year's PO budgets.
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077030', 'budget' => 10000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077030', 3000, '2025-06-01');

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertDontSee($this->inclCarryText('$7,000.00'));
    }

    public function test_no_live_carry_when_prior_year_fully_spent()
    {
        PurchaseOrder::factory()->create([
            'po_number' => 'P0077040', 'budget' => 5000, 'fiscal_year' => 'FY2025-26',
        ]);
        $this->commitViaAsset('P0077040', 5000, '2025-06-01');
        $this->makeTargetFySelectable();

        $this->actingAs($this->superuser())
            ->get(route('reports.procurement', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertDontSee(trans('admin/budget-allocations/general.live'));
    }
}
