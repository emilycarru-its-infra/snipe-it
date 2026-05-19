<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

class ForecastPlannedOrderTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function eolAsset(string $tag): Asset
    {
        $asset = Asset::factory()->create(['asset_tag' => $tag, 'purchase_cost' => 1200]);

        // The asset factory recomputes asset_eol_date in an afterMaking hook,
        // so pin it directly to a date inside the forecast window.
        Asset::query()->whereKey($asset->id)
            ->update(['asset_eol_date' => now()->addMonths(6)->format('Y-m-d')]);

        return $asset;
    }

    public function test_a_planned_order_can_be_created_from_forecast_assets()
    {
        $a1 = $this->eolAsset('EOL-1');
        $a2 = $this->eolAsset('EOL-2');

        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.forecast.plan'), [
                'order_number' => 'REFRESH-FY27',
                'fiscal_year' => 'FY2026-27',
                'assets' => [$a1->id, $a2->id],
            ])
            ->assertRedirect();

        $order = Order::where('order_number', 'REFRESH-FY27')->first();
        $this->assertNotNull($order);
        $this->assertTrue((bool) $order->is_planned);
        $this->assertEquals('FY2026-27', $order->fiscal_year);
        $this->assertEquals(2, $order->items()->count());

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'replaces_asset_id' => $a1->id,
        ]);
    }

    public function test_planned_line_items_carry_the_asset_replacement_estimate()
    {
        $asset = $this->eolAsset('EOL-COST');

        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.forecast.plan'), [
                'order_number' => 'REFRESH-COST',
                'assets' => [$asset->id],
            ]);

        $item = OrderItem::where('replaces_asset_id', $asset->id)->first();
        $this->assertNotNull($item);
        $this->assertEquals(1200.0, (float) $item->unit_cost);
        // A forecast line item is a future purchase, not yet received.
        $this->assertNull($item->received_at);
    }

    public function test_an_asset_with_a_planned_replacement_is_not_selectable_on_the_forecast()
    {
        $planned = $this->eolAsset('EOL-PLANNED');
        $open = $this->eolAsset('EOL-OPEN');

        $order = Order::factory()->create(['is_planned' => true, 'status' => 'ordered']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'replaces_asset_id' => $planned->id,
        ]);

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.forecast'))
            ->assertOk();

        // The open asset has a selection checkbox; the planned one shows a
        // "Planned" label in its place.
        $response->assertSee('name="assets[]" value="'.$open->id.'"', false);
        $response->assertDontSee('name="assets[]" value="'.$planned->id.'"', false);
    }

    public function test_a_device_cannot_be_double_booked_into_two_planned_orders()
    {
        $asset = $this->eolAsset('EOL-DOUBLE');
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->post(route('reports.procurement.forecast.plan'), [
                'order_number' => 'REFRESH-FIRST',
                'assets' => [$asset->id],
            ]);

        $this->actingAs($superuser)
            ->post(route('reports.procurement.forecast.plan'), [
                'order_number' => 'REFRESH-SECOND',
                'assets' => [$asset->id],
            ]);

        $this->assertEquals(1, OrderItem::where('replaces_asset_id', $asset->id)->count());
    }

    public function test_creating_a_planned_order_requires_at_least_one_asset()
    {
        $this->actingAs($this->superuser())
            ->post(route('reports.procurement.forecast.plan'), [
                'order_number' => 'REFRESH-EMPTY',
            ])
            ->assertSessionHasErrors('assets');
    }
}
