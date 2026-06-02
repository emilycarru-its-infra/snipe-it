<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use Tests\TestCase;

class PlannedOrdersTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_an_order_can_be_created_as_planned()
    {
        $this->actingAs($this->superuser())
            ->post(route('orders.store'), [
                'order_number' => 'PLAN-1',
                'is_planned' => '1',
                'fiscal_year' => 'FY2026-27',
            ])
            ->assertRedirect(route('orders.index'));

        $this->assertDatabaseHas('orders', [
            'order_number' => 'PLAN-1',
            'is_planned' => 1,
            'fiscal_year' => 'FY2026-27',
        ]);
    }

    public function test_a_planned_order_is_excluded_from_purchase_order_committed_spend()
    {
        $po = PurchaseOrder::factory()->create(['budget' => 10000]);

        $actual = Order::factory()->create([
            'status' => 'ordered',
            'is_planned' => false,
            'purchase_order_id' => $po->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $actual->id, 'purchase_order_id' => $po->id,
            'quantity' => 1, 'unit_cost' => 3000, 'warranty_cost' => 0,
        ]);

        $planned = Order::factory()->create([
            'status' => 'ordered',
            'is_planned' => true,
            'purchase_order_id' => $po->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $planned->id, 'purchase_order_id' => $po->id,
            'quantity' => 1, 'unit_cost' => 5000, 'warranty_cost' => 0,
        ]);

        // Only the actual order's line item counts; the planned order is excluded.
        $this->assertEquals(3000.0, $po->committedTotal());
    }

    public function test_capital_report_forecast_mode_includes_planned_orders()
    {
        $planned = Order::factory()->create([
            'status' => 'ordered',
            'is_planned' => true,
            'fiscal_year' => 'FY2026-27',
        ]);
        OrderItem::factory()->create(['order_id' => $planned->id, 'quantity' => 2, 'unit_cost' => 1000]);

        $superuser = $this->superuser();

        // Assert on the planned line's value rather than its fiscal-year
        // label: the FY now appears in the report's fiscal-year selector
        // regardless of mode, so the planned $2,000 is what distinguishes
        // the two modes.
        // Actual mode leaves planned orders out.
        $this->actingAs($superuser)
            ->get(route('reports.procurement.capital'))
            ->assertOk()
            ->assertDontSee('$2,000.00');

        // Forecast mode brings them in.
        $this->actingAs($superuser)
            ->get(route('reports.procurement.capital', ['mode' => 'forecast']))
            ->assertOk()
            ->assertSee('$2,000.00');
    }

    public function test_order_create_form_exposes_the_planned_fields()
    {
        $this->actingAs($this->superuser())
            ->get(route('orders.create'))
            ->assertOk()
            ->assertSee(trans('admin/orders/general.is_planned'));
    }
}
