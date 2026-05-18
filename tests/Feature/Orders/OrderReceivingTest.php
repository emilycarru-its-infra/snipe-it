<?php

namespace Tests\Feature\Orders;

use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\Statuslabel;
use App\Models\User;
use Tests\TestCase;

class OrderReceivingTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_receiving_one_line_item_marks_the_order_partially_received()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $items = OrderItem::factory()->count(2)->create(['order_id' => $order->id]);

        $this->actingAs($this->superuser())
            ->post(route('orders.items.receive', ['order' => $order->id, 'item' => $items[0]->id]))
            ->assertRedirect(route('orders.show', $order->id));

        $this->assertNotNull($items[0]->fresh()->received_at);
        $this->assertEquals('partially_received', $order->fresh()->status);
    }

    public function test_receiving_all_line_items_marks_the_order_received()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $items = OrderItem::factory()->count(2)->create(['order_id' => $order->id]);

        $superuser = $this->superuser();
        foreach ($items as $item) {
            $this->actingAs($superuser)
                ->post(route('orders.items.receive', ['order' => $order->id, 'item' => $item->id]));
        }

        $this->assertEquals('received', $order->fresh()->status);
    }

    public function test_unreceiving_a_line_item_reverts_the_order_status()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $items = OrderItem::factory()->count(2)->create([
            'order_id' => $order->id,
            'received_at' => now(),
        ]);
        $order->recalculateStatus();
        $this->assertEquals('received', $order->fresh()->status);

        $this->actingAs($this->superuser())
            ->post(route('orders.items.unreceive', ['order' => $order->id, 'item' => $items[0]->id]))
            ->assertRedirect(route('orders.show', $order->id));

        $this->assertNull($items[0]->fresh()->received_at);
        $this->assertEquals('partially_received', $order->fresh()->status);
    }

    public function test_an_asset_becoming_deployable_receives_its_line_item()
    {
        $undeployable = Statuslabel::factory()->create();
        $deployable = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create(['status_id' => $undeployable->id]);

        $order = Order::factory()->create(['status' => 'ordered']);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $asset->id,
        ]);
        $this->assertNull($item->fresh()->received_at);

        $asset->status_id = $deployable->id;
        $asset->save();

        $this->assertNotNull($item->fresh()->received_at);
        $this->assertEquals('received', $order->fresh()->status);
    }

    public function test_a_shipment_can_be_added_to_an_order()
    {
        $order = Order::factory()->create(['status' => 'ordered']);

        $this->actingAs($this->superuser())
            ->post(route('orders.shipments.store', $order->id), [
                'tracking_number' => '1Z-RECEIVE-TEST',
                'tracking_carrier' => 'UPS',
            ])
            ->assertRedirect(route('orders.show', $order->id));

        $this->assertDatabaseHas('order_shipments', [
            'order_id' => $order->id,
            'tracking_number' => '1Z-RECEIVE-TEST',
        ]);
    }

    public function test_receiving_a_shipment_receives_its_line_items()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        $shipment = OrderShipment::factory()->create(['order_id' => $order->id]);
        $items = OrderItem::factory()->count(2)->create([
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
        ]);

        $this->actingAs($this->superuser())
            ->post(route('orders.shipments.receive', ['order' => $order->id, 'shipment' => $shipment->id]))
            ->assertRedirect(route('orders.show', $order->id));

        foreach ($items as $item) {
            $this->assertNotNull($item->fresh()->received_at);
        }
        $this->assertNotNull($shipment->fresh()->received_date);
        $this->assertEquals('received', $order->fresh()->status);
    }

    public function test_sync_received_command_backfills_active_assets()
    {
        $deployable = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create(['status_id' => $deployable->id]);

        $order = Order::factory()->create(['status' => 'ordered']);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $asset->id,
        ]);

        // Simulate pre-backfill state: line item not yet marked received.
        OrderItem::query()->whereKey($item->id)->update(['received_at' => null]);
        Order::query()->whereKey($order->id)->update(['status' => 'ordered']);

        $this->artisan('orders:sync-received')->assertExitCode(0);

        $this->assertNotNull($item->fresh()->received_at);
        $this->assertEquals('received', $order->fresh()->status);
    }

    public function test_an_order_can_be_cancelled_and_reopened()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        OrderItem::factory()->create(['order_id' => $order->id]);
        $superuser = $this->superuser();

        $this->actingAs($superuser)
            ->post(route('orders.cancel', $order->id))
            ->assertRedirect(route('orders.show', $order->id));
        $this->assertEquals('cancelled', $order->fresh()->status);

        $this->actingAs($superuser)
            ->post(route('orders.reopen', $order->id))
            ->assertRedirect(route('orders.show', $order->id));
        $this->assertEquals('ordered', $order->fresh()->status);
    }

    public function test_order_export_streams_a_csv()
    {
        $order = Order::factory()->create(['status' => 'ordered']);
        OrderItem::factory()->count(2)->create(['order_id' => $order->id]);

        $response = $this->actingAs($this->superuser())
            ->get(route('orders.export', $order->id));

        $response->assertOk();
        $this->assertStringContainsString('Item Type', $response->streamedContent());
    }
}
