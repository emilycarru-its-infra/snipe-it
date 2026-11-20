<?php

namespace Tests\Feature\Orders\Api;

use App\Models\Consumable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\User;
use Tests\TestCase;

class ReceiveAnOrderShipmentApiTest extends TestCase
{
    private function endpoint(OrderShipment $shipment, ?Order $order = null): string
    {
        return route('api.orders.shipments.receive', ['order_id' => $order?->id ?? $shipment->order_id, 'shipment_id' => $shipment->id]);
    }

    public function test_only_the_matching_packages_lines_are_received_and_retries_preserve_dates(): void
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $shipment = OrderShipment::factory()->create(['order_id' => $order->id]);
        $other = OrderShipment::factory()->create(['order_id' => $order->id]);
        $stock = Consumable::factory()->create(['qty' => 2]);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'shipment_id' => $shipment->id,
            'item_type' => Consumable::class, 'item_id' => $stock->id, 'quantity' => 3]);
        $unrelated = OrderItem::factory()->create(['order_id' => $order->id, 'shipment_id' => $other->id]);
        $date = now()->subDay()->toDateString();
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson($this->endpoint($shipment), ['tracking_number' => $shipment->tracking_number, 'received_date' => $date])
            ->assertOk()->assertJsonPath('payload.newly_received', 1)
            ->assertJsonPath('payload.status', 'partially_received');
        $this->assertNotNull($item->fresh()->received_at);
        $this->assertNull($unrelated->fresh()->received_at);
        $this->assertNull($other->fresh()->received_date);
        $this->assertSame($date, $shipment->fresh()->received_date->toDateString());
        $this->assertSame(5, (int) $stock->fresh()->qty);
        $received = $item->fresh()->received_at;
        $this->postJson($this->endpoint($shipment), ['tracking_number' => $shipment->tracking_number])
            ->assertOk()->assertJsonPath('payload.newly_received', 0);
        $this->assertEquals($received, $item->fresh()->received_at);
        $this->assertSame($date, $shipment->fresh()->received_date->toDateString());
        $this->assertSame(5, (int) $stock->fresh()->qty);
    }

    public function test_a_stale_tracking_identity_or_other_orders_shipment_cannot_receive_anything(): void
    {
        $shipment = OrderShipment::factory()->create(['order_id' => Order::factory()->create(['status' => 'ordered'])->id]);
        $item = OrderItem::factory()->create(['order_id' => $shipment->order_id, 'shipment_id' => $shipment->id]);
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson($this->endpoint($shipment), ['tracking_number' => 'different-package'])->assertStatus(409);
        $this->postJson($this->endpoint($shipment, Order::factory()->create(['status' => 'ordered'])), ['tracking_number' => $shipment->tracking_number])->assertNotFound();
        $this->assertNull($item->fresh()->received_at);
        $this->assertNull($shipment->fresh()->received_date);
    }

    public function test_order_edit_permission_is_required(): void
    {
        $shipment = OrderShipment::factory()->create(['order_id' => Order::factory()->create(['status' => 'ordered'])->id]);
        $this->actingAsForApi(User::factory()->create())
            ->postJson($this->endpoint($shipment), ['tracking_number' => $shipment->tracking_number])->assertForbidden();
        $this->assertNull($shipment->fresh()->received_date);
    }

    public function test_cancelled_and_planned_orders_cannot_be_received(): void
    {
        foreach ([['status' => 'cancelled'], ['is_planned' => true]] as $attributes) {
            $order = Order::factory()->create($attributes);
            $shipment = OrderShipment::factory()->create(['order_id' => $order->id]);
            $this->actingAsForApi(User::factory()->superuser()->create())
                ->postJson($this->endpoint($shipment), ['tracking_number' => $shipment->tracking_number])->assertUnprocessable();
            $this->assertNull($shipment->fresh()->received_date);
        }
    }

    public function test_missing_identity_and_future_dates_are_rejected(): void
    {
        $shipment = OrderShipment::factory()->create(['order_id' => Order::factory()->create(['status' => 'ordered'])->id]);
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson($this->endpoint($shipment), [])->assertOk()->assertJsonPath('status', 'error');
        $this->postJson($this->endpoint($shipment), ['tracking_number' => $shipment->tracking_number,
            'received_date' => now()->addDay()->toDateString()])->assertOk()->assertJsonPath('status', 'error');
        $this->assertNull($shipment->fresh()->received_date);
    }

    public function test_an_already_received_orders_missing_shipment_date_can_be_reconciled(): void
    {
        $shipment = OrderShipment::factory()->create(['order_id' => Order::factory()->create(['status' => 'ordered'])->id]);
        $item = OrderItem::factory()->create(['order_id' => $shipment->order_id, 'shipment_id' => $shipment->id, 'received_at' => now()->subDay()]);
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson($this->endpoint($shipment), ['tracking_number' => $shipment->tracking_number])
            ->assertOk()->assertJsonPath('payload.newly_received', 0)->assertJsonPath('payload.status', 'received');
        $this->assertNotNull($shipment->fresh()->received_date);
    }
}
