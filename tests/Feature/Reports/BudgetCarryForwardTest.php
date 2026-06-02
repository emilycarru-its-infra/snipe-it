<?php

namespace Tests\Feature\Reports;

use App\Models\BudgetAllocation;
use App\Models\Order;
use App\Models\OrderItem;
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
     * Seed an approved budget for $fy and commit $committed against it via a
     * line item on an order booked in that fiscal year.
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
            $order = Order::factory()->create([
                'fiscal_year'       => $fy,
                'is_planned'        => false,
                'purchase_order_id' => $po->id,
            ]);
            OrderItem::create([
                'order_id'          => $order->id,
                'purchase_order_id' => $po->id,
                'description'       => 'committed line',
                'quantity'          => 1,
                'unit_cost'         => $committed,
                'warranty_cost'     => 0,
            ]);
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
}
