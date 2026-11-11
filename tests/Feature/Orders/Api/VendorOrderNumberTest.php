<?php

namespace Tests\Feature\Orders\Api;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

/**
 * Once the vendor issues an order number, it is the order's address; the
 * number the order carried before keeps opening it.
 */
class VendorOrderNumberTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_the_api_sets_the_vendor_number_and_logs_old_and_new()
    {
        $order = Order::factory()->create(['order_number' => 'ORD-TEMP-1']);
        $asset = Asset::factory()->create(['order_number' => 'ORD-TEMP-1']);
        $actor = $this->superuser();

        $this->actingAsForApi($actor)
            ->patchJson(route('api.orders.vendor-order', $order->id), ['vendor_order_number' => 'VEND-123'])
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'payload' => [
                    'id' => $order->id,
                    'vendor_order_number' => 'VEND-123',
                    'order_number' => 'VEND-123',
                    'display_number' => 'VEND-123',
                    'secondary_number' => 'ORD-TEMP-1',
                    'route_key' => 'VEND-123',
                ],
            ]);

        $order->refresh();
        $this->assertSame('VEND-123', $order->vendor_order_number);
        $this->assertSame('VEND-123', $order->order_number);
        $this->assertSame('ORD-TEMP-1', $order->internal_order_number);
        $this->assertSame('VEND-123', $asset->refresh()->order_number);

        $log = Actionlog::where('item_type', Order::class)->where('item_id', $order->id)->latest('id')->first();
        $this->assertSame($actor->id, (int) $log->created_by);
        $meta = json_decode($log->log_meta, true);
        $this->assertSame(['old' => null, 'new' => 'VEND-123'], $meta['vendor_order_number']);
        $this->assertSame(['old' => 'ORD-TEMP-1', 'new' => 'VEND-123'], $meta['order_number']);
    }

    public function test_clearing_the_vendor_number_gives_the_order_its_own_back()
    {
        $order = Order::factory()->create(['order_number' => 'ORD-TEMP-2']);
        $actor = $this->superuser();

        $this->actingAsForApi($actor)
            ->patchJson(route('api.orders.vendor-order', $order->id), ['vendor_order_number' => 'VEND-456'])
            ->assertOk();
        $this->actingAsForApi($actor)
            ->patchJson(route('api.orders.vendor-order', $order->id), ['vendor_order_number' => null])
            ->assertOk()
            ->assertJson(['status' => 'success', 'payload' => ['vendor_order_number' => null, 'order_number' => 'ORD-TEMP-2']]);

        $order->refresh();
        $this->assertNull($order->vendor_order_number);
        $this->assertNull($order->internal_order_number);
        $this->assertSame('ORD-TEMP-2', $order->order_number);
    }

    public function test_a_missing_field_or_a_viewer_is_refused()
    {
        $order = Order::factory()->create(['order_number' => 'ORD-TEMP-3']);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.orders.vendor-order', $order->id), [])
            ->assertStatus(422)
            ->assertJson(['status' => 'error']);

        $viewer = User::factory()->create(['permissions' => json_encode(['orders.view' => '1'])]);
        $this->actingAsForApi($viewer)
            ->patchJson(route('api.orders.vendor-order', $order->id), ['vendor_order_number' => 'VEND-789'])
            ->assertForbidden();

        $this->assertNull($order->refresh()->vendor_order_number);
    }

    public function test_the_old_number_and_the_id_forward_to_the_vendor_number_address()
    {
        $order = Order::factory()->create(['order_number' => 'ORD-TEMP-4']);
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.orders.vendor-order', $order->id), ['vendor_order_number' => 'VEND-ADDR'])
            ->assertOk();
        $order->refresh();
        $admin = $this->superuser();

        $this->assertStringEndsWith('/procurement/orders/VEND-ADDR', route('orders.show', $order));
        $this->actingAs($admin, 'web')->get('/procurement/orders/ORD-TEMP-4')
            ->assertRedirect(route('orders.show', $order));
        $this->actingAs($admin, 'web')->get('/procurement/orders/'.$order->id.'?tab=lines')
            ->assertRedirect(route('orders.show', $order).'?tab=lines');
        $this->actingAs($admin, 'web')->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('VEND-ADDR')
            ->assertSee('ORD-TEMP-4');
    }

    public function test_an_order_whose_number_was_never_renamed_is_addressed_by_the_vendor_number()
    {
        // A vendor number recorded without the rename, as a hand edit leaves it.
        $order = Order::factory()->create(['order_number' => 'ORD-TEMP-5', 'vendor_order_number' => 'VEND-HAND']);
        $admin = $this->superuser();

        $this->assertStringEndsWith('/procurement/orders/VEND-HAND', route('orders.show', $order));
        $this->actingAs($admin, 'web')->get('/procurement/orders/ORD-TEMP-5')
            ->assertRedirect(route('orders.show', $order));
    }

    public function test_a_vendor_number_two_orders_share_falls_back_to_the_order_number()
    {
        $first = Order::factory()->create(['order_number' => 'ORD-TEMP-6', 'vendor_order_number' => 'VEND-SHARED']);
        $second = Order::factory()->create(['order_number' => 'ORD-TEMP-7', 'vendor_order_number' => 'VEND-SHARED']);

        $this->assertStringEndsWith('/procurement/orders/ORD-TEMP-6', route('orders.show', $first));
        $this->assertStringEndsWith('/procurement/orders/ORD-TEMP-7', route('orders.show', $second));
        $this->actingAs($this->superuser(), 'web')->get(route('orders.show', $first))->assertOk();
    }

    public function test_a_vendor_number_another_order_is_named_by_is_recorded_without_the_rename()
    {
        Order::factory()->create(['order_number' => 'VEND-TAKEN']);
        $order = Order::factory()->create(['order_number' => 'ORD-TEMP-8']);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.orders.vendor-order', $order->id), ['vendor_order_number' => 'VEND-TAKEN'])
            ->assertOk();

        $order->refresh();
        $this->assertSame('VEND-TAKEN', $order->vendor_order_number);
        $this->assertSame('ORD-TEMP-8', $order->order_number);
        $this->assertStringEndsWith('/procurement/orders/ORD-TEMP-8', route('orders.show', $order));
    }

    public function test_search_finds_an_order_by_either_number()
    {
        $order = Order::factory()->create(['order_number' => 'VEND-SEARCH', 'vendor_order_number' => 'VEND-SEARCH', 'internal_order_number' => 'ORD-TEMP-9']);
        $admin = $this->superuser();

        $this->actingAsForApi($admin)->getJson(route('api.orders.index', ['search' => 'ORD-TEMP-9']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.id', $order->id)
            ->assertJsonPath('rows.0.display_number', 'VEND-SEARCH');

        $this->actingAs($admin, 'web')->getJson(route('search.suggest', ['q' => 'ORD-TEMP-9']))
            ->assertOk()
            ->assertSee('VEND-SEARCH');
    }
}
