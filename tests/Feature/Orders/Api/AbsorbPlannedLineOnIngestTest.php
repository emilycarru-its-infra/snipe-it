<?php

namespace Tests\Feature\Orders\Api;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

/**
 * An order is raised as a model line — "2 of this model" — and that line
 * provisions the serial-less assets. When the vendor invoice names the
 * serials, the ingest writes one line per asset. Carrying both would double
 * the order's cost: the same devices counted once as planned, once as billed.
 */
class AbsorbPlannedLineOnIngestTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function ingest(array $payload)
    {
        return $this->actingAsForApi($this->superuser())
            ->postJson(route('api.orders.ingest'), $payload);
    }

    public function test_an_arriving_asset_comes_off_the_planning_line()
    {
        $model = AssetModel::factory()->create();
        $order = Order::factory()->create(['order_number' => 'ORD-4471', 'status' => 'ordered']);
        $planned = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => AssetModel::class,
            'item_id' => $model->id,
            'quantity' => 2,
            'unit_cost' => 120.00,
        ]);
        $asset = Asset::factory()->create(['model_id' => $model->id]);

        $this->ingest([
            'order_number' => 'ORD-4471',
            'invoice' => ['invoice_number' => 'INV-90210', 'subtotal' => 240.00, 'total' => 252.00],
            'items' => [
                ['asset_id' => $asset->id, 'description' => 'STYLUS PEN', 'quantity' => 1, 'unit_cost' => 120.00],
            ],
        ])->assertOk();

        // One of the two planned units is now accounted for by name.
        $this->assertDatabaseHas('order_items', ['id' => $planned->id, 'quantity' => 1]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $asset->id,
        ]);
    }

    public function test_the_planning_line_goes_when_every_unit_has_arrived()
    {
        $model = AssetModel::factory()->create();
        $order = Order::factory()->create(['order_number' => 'ORD-4471', 'status' => 'ordered']);
        $planned = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => AssetModel::class,
            'item_id' => $model->id,
            'quantity' => 2,
            'unit_cost' => 120.00,
        ]);
        $first = Asset::factory()->create(['model_id' => $model->id]);
        $second = Asset::factory()->create(['model_id' => $model->id]);

        $this->ingest([
            'order_number' => 'ORD-4471',
            'invoice' => ['invoice_number' => 'INV-90210', 'subtotal' => 240.00, 'total' => 252.00],
            'items' => [
                ['asset_id' => $first->id, 'description' => 'STYLUS PEN', 'quantity' => 1, 'unit_cost' => 120.00],
                ['asset_id' => $second->id, 'description' => 'STYLUS PEN', 'quantity' => 1, 'unit_cost' => 120.00],
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('order_items', ['id' => $planned->id]);

        // The order is now described entirely by the two identified units.
        $order->refresh()->load('items');
        $this->assertSame(2, $order->items->count());
        $this->assertEqualsWithDelta(
            240.00,
            $order->items->sum(fn ($li) => (float) $li->unit_cost * (int) $li->quantity),
            0.01
        );
    }

    public function test_a_re_posted_invoice_does_not_absorb_twice()
    {
        $model = AssetModel::factory()->create();
        $order = Order::factory()->create(['order_number' => 'ORD-4471', 'status' => 'ordered']);
        $planned = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => AssetModel::class,
            'item_id' => $model->id,
            'quantity' => 3,
            'unit_cost' => 120.00,
        ]);
        $asset = Asset::factory()->create(['model_id' => $model->id]);

        $payload = [
            'order_number' => 'ORD-4471',
            'invoice' => ['invoice_number' => 'INV-90210', 'subtotal' => 120.00, 'total' => 126.00],
            'items' => [
                ['asset_id' => $asset->id, 'description' => 'STYLUS PEN', 'quantity' => 1, 'unit_cost' => 120.00],
            ],
        ];

        $this->ingest($payload)->assertOk();
        $this->ingest($payload)->assertOk();

        // CDW re-sending an invoice is routine; the second post must be inert.
        $this->assertDatabaseHas('order_items', ['id' => $planned->id, 'quantity' => 2]);
    }

    public function test_a_mismatched_model_leaves_the_plan_alone()
    {
        $planningModel = AssetModel::factory()->create();
        $otherModel = AssetModel::factory()->create();
        $order = Order::factory()->create(['order_number' => 'ORD-4471', 'status' => 'ordered']);
        $planned = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => AssetModel::class,
            'item_id' => $planningModel->id,
            'quantity' => 2,
            'unit_cost' => 120.00,
        ]);
        $asset = Asset::factory()->create(['model_id' => $otherModel->id]);

        $this->ingest([
            'order_number' => 'ORD-4471',
            'invoice' => ['invoice_number' => 'INV-90210', 'subtotal' => 120.00, 'total' => 126.00],
            'items' => [
                ['asset_id' => $asset->id, 'description' => 'SOMETHING ELSE', 'quantity' => 1, 'unit_cost' => 120.00],
            ],
        ])->assertOk();

        // Writing off a unit that is still owed would be worse than a
        // visible duplicate, so a mismatch absorbs nothing.
        $this->assertDatabaseHas('order_items', ['id' => $planned->id, 'quantity' => 2]);
    }

    public function test_a_non_asset_line_does_not_touch_the_plan()
    {
        $model = AssetModel::factory()->create();
        $order = Order::factory()->create(['order_number' => 'ORD-4471', 'status' => 'ordered']);
        $planned = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => AssetModel::class,
            'item_id' => $model->id,
            'quantity' => 2,
            'unit_cost' => 120.00,
        ]);

        $this->ingest([
            'order_number' => 'ORD-4471',
            'invoice' => ['invoice_number' => 'INV-90210', 'subtotal' => 0.50, 'total' => 0.53],
            'items' => [
                ['description' => 'RECYCLING FEE', 'quantity' => 1, 'unit_cost' => 0.50],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('order_items', ['id' => $planned->id, 'quantity' => 2]);
    }
}
