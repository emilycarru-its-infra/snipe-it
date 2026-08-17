<?php

namespace Tests\Feature\Orders;

use App\Mail\StoreOrderStatusMail;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\ArrivalAllocator;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rod's eight-MacBooks case: CDW ships eight, five were ordered through
 * the store, three are extras. The webhook claims the five automatically;
 * the extras land as stock. Allocation is the human pairing of an extra
 * with a request that appeared after the shipment — same end state as the
 * automatic claim, chosen instead of FIFO.
 */
class ArrivalAllocationTest extends TestCase
{
    private Statuslabel $pending;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pending = Statuslabel::factory()->create([
            'name' => 'New (Ordered)', 'pending' => 1, 'archived' => 0, 'deployable' => 0,
        ]);
    }

    private function waitingAsset(AssetModel $model, int $storeOrderId, string $name = 'Frida Kahlo'): Asset
    {
        return Asset::factory()->create([
            'model_id' => $model->id,
            'status_id' => $this->pending->id,
            'serial' => null,
            'name' => $name,
            'order_number' => 'ECU-STORE-'.$storeOrderId,
            'assigned_to' => null,
        ]);
    }

    private function arrivalAsset(AssetModel $model, string $serial = 'C02ARRIVED1'): Asset
    {
        return Asset::factory()->create([
            'model_id' => $model->id,
            'status_id' => $this->pending->id,
            'serial' => $serial,
            'order_number' => 'CDW123456',
            'purchase_cost' => 2811.50,
            'purchase_date' => '2026-07-28',
            'assigned_to' => null,
        ]);
    }

    public function test_the_pools_are_disjoint_and_correctly_scoped()
    {
        $model = AssetModel::factory()->create();
        $storeOrder = StoreOrder::create(['user_id' => User::factory()->create()->id, 'status' => 'ordered']);

        $waiting = $this->waitingAsset($model, $storeOrder->id);
        $arrival = $this->arrivalAsset($model);

        // Deployed hardware and claimed records belong to neither pool.
        Asset::factory()->create(['serial' => 'C02DEPLOYED', 'assigned_to' => User::factory()->create()->id,
            'assigned_type' => User::class, 'status_id' => $this->pending->id]);

        $allocator = app(ArrivalAllocator::class);

        $this->assertSame([$arrival->id], $allocator->unallocatedArrivals()->pluck('id')->all());
        $this->assertSame([$waiting->id], $allocator->waitingRequests()->pluck('id')->all());
    }

    public function test_allocation_moves_the_physical_facts_and_keeps_the_identity()
    {
        Mail::fake();

        $model = AssetModel::factory()->create();
        $requester = User::factory()->create();
        $storeOrder = StoreOrder::create(['user_id' => $requester->id, 'status' => 'ordered']);
        StoreOrderItem::create(['store_order_id' => $storeOrder->id, 'description' => 'MacBook Air',
            'quantity' => 1, 'unit_cost' => 2100]);

        $waiting = $this->waitingAsset($model, $storeOrder->id);
        $arrival = $this->arrivalAsset($model);

        // The CDW order's line item points at the arrival record.
        $cdwOrder = Order::create(['order_number' => 'CDW123456', 'status' => 'shipped']);
        $line = OrderItem::create(['order_id' => $cdwOrder->id, 'item_type' => Asset::class,
            'item_id' => $arrival->id, 'quantity' => 1, 'unit_cost' => 2811.50]);

        app(ArrivalAllocator::class)->allocate($arrival, $waiting);

        $waiting->refresh();

        // Physical facts travelled; identity stayed.
        $this->assertSame('C02ARRIVED1', $waiting->serial);
        $this->assertEqualsWithDelta(2811.50, (float) $waiting->purchase_cost, 0.01);
        $this->assertSame('Frida Kahlo', $waiting->name);
        $this->assertSame('ECU-STORE-'.$storeOrder->id, $waiting->order_number);

        // The CDW line now names the surviving record; the arrival is gone.
        $this->assertSame($waiting->id, (int) $line->fresh()->item_id);
        $this->assertNull(Asset::find($arrival->id));

        // And the requester's order shipped, with the email to prove it.
        $storeOrder->refresh();
        $this->assertNotNull($storeOrder->shipped_at);
        Mail::assertSent(StoreOrderStatusMail::class, fn ($mail) => $mail->event === 'shipped'
            && $mail->hasTo($requester->email));
    }

    public function test_a_different_model_never_allocates()
    {
        $storeOrder = StoreOrder::create(['user_id' => User::factory()->create()->id, 'status' => 'ordered']);
        $waiting = $this->waitingAsset(AssetModel::factory()->create(), $storeOrder->id);
        $arrival = $this->arrivalAsset(AssetModel::factory()->create());

        $this->expectException(\InvalidArgumentException::class);
        app(ArrivalAllocator::class)->allocate($arrival, $waiting);
    }

    public function test_the_unmatched_page_offers_the_pairing()
    {
        $model = AssetModel::factory()->create(['name' => 'MacBook Air 13-inch (M5)']);
        $storeOrder = StoreOrder::create(['user_id' => User::factory()->create()->id, 'status' => 'ordered']);
        $waiting = $this->waitingAsset($model, $storeOrder->id, 'Lorand Example');
        $arrival = $this->arrivalAsset($model, 'C02EXTRA1');

        // The panel lives on its own page now; the orders list carries a
        // doorway button with the count.
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee(route('orders.unmatched', [], false), false)
            ->assertDontSee('C02EXTRA1', false);

        $page = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.unmatched'))
            ->assertOk();

        $page->assertSee('Awaiting allocation', false);
        $page->assertSee('C02EXTRA1', false);
        $page->assertSee('Lorand Example', false);
        $page->assertSee($waiting->asset_tag, false);
    }

    public function test_an_arrival_with_no_matching_model_reads_as_stock()
    {
        $this->arrivalAsset(AssetModel::factory()->create(), 'C02LONELY1');

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.unmatched'))
            ->assertOk()
            ->assertSee('C02LONELY1', false)
            ->assertSee('it stays as stock', false);
    }
}
