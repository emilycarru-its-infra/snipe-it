<?php

namespace Tests\Feature\Store;

use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The approval queue over the API. The web queue is one door onto these
 * decisions and this is the other — a script, a scheduled job or an agent
 * answering "what is waiting, and for how much" without driving a browser
 * session, and deciding without clicking eighteen buttons.
 */
class StoreOrdersApiTest extends TestCase
{
    private function procurement(): User
    {
        return User::factory()->superuser()->create();
    }

    private function orderFor(User $requester, float $unitCost = 2100.00): StoreOrder
    {
        $item = CatalogItem::create([
            'name' => 'MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'family' => 'MacBook Air',
            'category' => 'Laptops',
            'product_type' => 'standard',
            'vendor_sku' => '9094662',
            'unit_cost' => $unitCost,
            'price_type' => 'quoted',
            'model_id' => AssetModel::factory()->create()->getKey(),
            'show_in_store' => 1,
        ]);

        $order = StoreOrder::create([
            'user_id' => $requester->id,
            'status' => 'pending',
            'program' => 'faculty',
        ]);

        StoreOrderItem::create([
            'store_order_id' => $order->id,
            'catalog_item_id' => $item->id,
            'description' => $item->name,
            'quantity' => 1,
            'unit_cost' => $unitCost,
        ]);

        return $order->load('items');
    }

    public function test_the_queue_is_readable_over_the_api()
    {
        $requester = User::factory()->create();
        $this->orderFor($requester);

        Passport::actingAs($this->procurement());

        $response = $this->getJson(route('api.store-orders.index', ['status' => 'pending']))
            ->assertOk();

        $this->assertSame(1, $response->json('total'));

        $row = $response->json('rows.0');
        $this->assertSame('pending', $row['status']);
        $this->assertSame(2100.00, $row['total']);
        $this->assertSame($requester->email, $row['requester']['email']);
        // No account yet, so it cannot be sent to the vendor.
        $this->assertFalse($row['ready_for_vendor']);
        $this->assertCount(1, $row['items']);
    }

    public function test_an_order_can_be_approved_over_the_api()
    {
        Mail::fake();

        $order = $this->orderFor(User::factory()->create());

        Passport::actingAs($this->procurement());

        $this->postJson(route('api.store-orders.decide', $order->id), [
            'decision' => 'approved',
            'funding_account' => 'purchase_admin',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.status', 'approved');

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame('purchase_admin', $order->fresh()->funding_account);
        $this->assertNotNull($order->fresh()->decided_at);
    }

    public function test_a_lease_account_keeps_the_schedule_it_was_decided_with()
    {
        Mail::fake();

        $order = $this->orderFor(User::factory()->create());

        Passport::actingAs($this->procurement());

        $this->postJson(route('api.store-orders.decide', $order->id), [
            'decision' => 'approved',
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-007',
        ])->assertOk();

        // The stored accounts are lease_admin and lease_curriculum, so the
        // old comparison against the bare string 'lease' dropped the
        // schedule and left every lease order unable to reach the vendor.
        $fresh = $order->fresh();
        $this->assertSame('301452-007', $fresh->lease_schedule);
        $this->assertTrue($fresh->readyForVendor());
    }

    public function test_a_purchase_account_carries_no_schedule()
    {
        Mail::fake();

        $order = $this->orderFor(User::factory()->create());

        Passport::actingAs($this->procurement());

        $this->postJson(route('api.store-orders.decide', $order->id), [
            'decision' => 'approved',
            'funding_account' => 'purchase_admin',
            'lease_schedule' => '301452-007',
        ])->assertOk();

        $this->assertNull($order->fresh()->lease_schedule);
        $this->assertTrue($order->fresh()->readyForVendor());
    }

    public function test_a_decided_order_is_not_decided_twice()
    {
        Mail::fake();

        $order = $this->orderFor(User::factory()->create());
        $order->update(['status' => 'approved']);

        Passport::actingAs($this->procurement());

        $this->postJson(route('api.store-orders.decide', $order->id), ['decision' => 'declined'])
            ->assertStatus(422);

        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_approved_orders_can_draw_on_an_existing_purchase_order()
    {
        Mail::fake();

        $purchaseOrder = PurchaseOrder::factory()->create([
            'po_number' => 'P0099100',
            'fiscal_year' => 'FY2026-27',
            'budget' => 178640.00,
        ]);

        $first = $this->orderFor(User::factory()->create());
        $second = $this->orderFor(User::factory()->create(), 2700.00);
        StoreOrder::whereIn('id', [$first->id, $second->id])->update(['status' => 'approved']);

        Passport::actingAs($this->procurement());

        $this->postJson(route('api.store-orders.attach'), [
            'orders' => [$first->id, $second->id],
            'purchase_order_id' => $purchaseOrder->id,
        ])
            ->assertOk()
            ->assertJsonPath('payload.attached', 2)
            ->assertJsonPath('payload.requested_total', 4800.00);

        $this->assertSame($purchaseOrder->id, $first->fresh()->purchase_order_id);

        // The request is reported beside committed spend, never inside it —
        // a device funded twice is the failure this linkage exists to end.
        $this->assertSame(0.0, round($purchaseOrder->fresh()->committedTotal(), 2));
    }

    public function test_a_pending_order_cannot_be_attached_to_a_purchase_order()
    {
        $purchaseOrder = PurchaseOrder::factory()->create(['po_number' => 'P0099101']);
        $order = $this->orderFor(User::factory()->create());

        Passport::actingAs($this->procurement());

        $this->postJson(route('api.store-orders.attach'), [
            'orders' => [$order->id],
            'purchase_order_id' => $purchaseOrder->id,
        ])->assertStatus(422);

        $this->assertNull($order->fresh()->purchase_order_id);
    }

    public function test_the_queue_is_not_open_to_everyone()
    {
        $this->orderFor(User::factory()->create());

        Passport::actingAs(User::factory()->create());

        $this->getJson(route('api.store-orders.index'))->assertForbidden();
    }
}
