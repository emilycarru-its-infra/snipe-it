<?php

namespace Tests\Feature\Store;

use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The approval queue over the API. The web queue is one door onto these
 * decisions and this is the other — a script, a scheduled job or an agent
 * answering "what is waiting, and for how much" without driving a browser
 * session, and deciding without clicking eighteen buttons.
 */
class StoreOrdersApiTest extends TestCase
{
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

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->getJson(route('api.store-orders.index', ['status' => 'pending']))
            ->assertOk();

        $this->assertSame(1, $response->json('total'));

        $row = $response->json('rows.0');
        $this->assertSame('pending', $row['status']);
        $this->assertSame(2100.00, $row['total']);
        $this->assertSame($requester->email, $row['requester']['email']);
        // No account yet, so it cannot be sent to the vendor.
        $this->assertFalse($row['ready_for_vendor']);
        $this->assertSame(1, count($row['items']));
    }

    public function test_an_order_can_be_approved_over_the_api()
    {
        Mail::fake();

        $order = $this->orderFor(User::factory()->create());

        $this->actingAs(User::factory()->superuser()->create())
            ->postJson(route('api.store-orders.decide', $order->id), [
                'decision' => 'approved',
                'funding_account' => 'lease_curriculum',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.status', 'approved');

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame('lease_curriculum', $order->fresh()->funding_account);
        $this->assertNotNull($order->fresh()->decided_at);
    }

    public function test_a_decided_order_is_not_decided_twice()
    {
        Mail::fake();

        $order = $this->orderFor(User::factory()->create());
        $order->update(['status' => 'approved']);

        $this->actingAs(User::factory()->superuser()->create())
            ->postJson(route('api.store-orders.decide', $order->id), ['decision' => 'declined'])
            ->assertStatus(422);

        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_the_queue_is_not_open_to_everyone()
    {
        $this->orderFor(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->getJson(route('api.store-orders.index'))
            ->assertForbidden();
    }
}
